package app.fluide;

import android.app.ActivityManager;
import android.content.ContentResolver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageManager;
import android.hardware.display.DisplayManager;
import android.os.BatteryManager;
import android.os.Build;
import android.os.Environment;
import android.os.PowerManager;
import android.os.StatFs;
import android.os.SystemClock;
import android.provider.Settings;
import android.view.Display;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;

/**
 * Photographie de l'état du téléphone, puis constats classés.
 * collect() lit le système ; findings() est une fonction pure (testable sans téléphone).
 */
final class Diagnostics {
    // Actions proposées par les constats (traitées par MainActivity).
    static final int ACT_NONE = 0, ACT_TAB_CLEAN = 1, ACT_TAB_TWEAKS = 2, ACT_TAB_MEASURE = 3,
            ACT_BATTERY = 4, ACT_DISPLAY = 5;

    static final long GB = 1000L * 1000 * 1000;

    static final class Snapshot {
        String manufacturer = "", model = "", release = "", patch = "", soc = "";
        int sdk;
        long ramTotal, ramAvail;
        int ramNominalGb;
        boolean lowRamDevice;
        long storageTotal, storageFree;
        float refreshNow, refreshMax, refreshMaxAnyRes;
        float peakSetting = -1, minSetting = -1; // -1 : illisible ou absent
        float animWindow = 1f, animTransition = 1f, animAnimator = 1f;
        boolean powerSave;
        int thermal = -1; // -1 : inconnu (avant Android 10)
        float batteryTemp = Float.NaN;
        long uptimeMs;
        int userApps;
        int perfClass;
        boolean devOptions;
        boolean canWriteSecure;
        List<Bloat.Entry> bloat = Collections.emptyList();

        double freePct() {
            return storageTotal > 0 ? 100.0 * storageFree / storageTotal : 100.0;
        }
    }

    static final class Finding {
        final int level;
        final String title;
        final String detail;
        final String actionLabel;
        final int action;

        Finding(int level, String title, String detail, String actionLabel, int action) {
            this.level = level;
            this.title = title;
            this.detail = detail;
            this.actionLabel = actionLabel;
            this.action = action;
        }
    }

    private Diagnostics() {}

    // ------------------------------------------------------------------ lecture

    static Snapshot collect(Context c) {
        Snapshot s = new Snapshot();
        s.manufacturer = cap(Build.MANUFACTURER);
        s.model = Build.MODEL == null ? "" : Build.MODEL;
        s.sdk = Build.VERSION.SDK_INT;
        s.release = Build.VERSION.RELEASE == null ? "" : Build.VERSION.RELEASE;
        s.patch = Build.VERSION.SECURITY_PATCH == null ? "" : Build.VERSION.SECURITY_PATCH;
        s.soc = soc();

        ActivityManager am = c.getSystemService(ActivityManager.class);
        if (am != null) {
            ActivityManager.MemoryInfo mi = new ActivityManager.MemoryInfo();
            am.getMemoryInfo(mi);
            s.ramTotal = mi.totalMem;
            s.ramAvail = mi.availMem;
            s.lowRamDevice = am.isLowRamDevice();
        }
        s.ramNominalGb = nominalGb(s.ramTotal);

        try {
            StatFs fs = new StatFs(Environment.getDataDirectory().getPath());
            s.storageTotal = fs.getTotalBytes();
            s.storageFree = fs.getAvailableBytes();
        } catch (IllegalArgumentException ignored) {
            // stockage illisible : on laisse 0
        }

        readDisplay(c, s);

        ContentResolver cr = c.getContentResolver();
        float[] anim = SystemTweaks.readAnim(cr);
        s.animWindow = anim[0];
        s.animTransition = anim[1];
        s.animAnimator = anim[2];
        s.peakSetting = readSystemFloat(cr, "peak_refresh_rate");
        s.minSetting = readSystemFloat(cr, "min_refresh_rate");
        s.devOptions = Settings.Global.getInt(cr, Settings.Global.DEVELOPMENT_SETTINGS_ENABLED, 0) == 1;
        s.canWriteSecure = SystemTweaks.canWrite(c);

        PowerManager pm = c.getSystemService(PowerManager.class);
        if (pm != null) {
            s.powerSave = pm.isPowerSaveMode();
            if (Build.VERSION.SDK_INT >= 29) s.thermal = pm.getCurrentThermalStatus();
        }
        s.batteryTemp = batteryTemp(c);
        s.uptimeMs = SystemClock.elapsedRealtime();
        if (Build.VERSION.SDK_INT >= 31) s.perfClass = Build.VERSION.MEDIA_PERFORMANCE_CLASS;

        PackageManager pkm = c.getPackageManager();
        int n = 0;
        for (ApplicationInfo ai : pkm.getInstalledApplications(0)) {
            if ((ai.flags & ApplicationInfo.FLAG_SYSTEM) == 0) n++;
        }
        s.userApps = n;
        s.bloat = Bloat.findInstalled(pkm);
        return s;
    }

    static void readDisplay(Context c, Snapshot s) {
        DisplayManager dm = c.getSystemService(DisplayManager.class);
        Display d = dm == null ? null : dm.getDisplay(Display.DEFAULT_DISPLAY);
        if (d == null) {
            s.refreshNow = s.refreshMax = s.refreshMaxAnyRes = 60f;
            return;
        }
        s.refreshNow = d.getRefreshRate();
        float maxSame = s.refreshNow;
        float maxAny = s.refreshNow;
        Display.Mode cur = d.getMode();
        for (Display.Mode m : d.getSupportedModes()) {
            maxAny = Math.max(maxAny, m.getRefreshRate());
            if (m.getPhysicalWidth() == cur.getPhysicalWidth() && m.getPhysicalHeight() == cur.getPhysicalHeight()) {
                maxSame = Math.max(maxSame, m.getRefreshRate());
            }
        }
        s.refreshMax = maxSame;
        s.refreshMaxAnyRes = maxAny;
    }

    /** Les réglages cachés ne sont pas tous lisibles depuis Android 12 : on renvoie -1 dans ce cas. */
    static float readSystemFloat(ContentResolver cr, String key) {
        try {
            String v = Settings.System.getString(cr, key);
            if (v == null) return -1f;
            float f = Float.parseFloat(v.trim());
            return Float.isInfinite(f) ? 10000f : f;
        } catch (SecurityException | NumberFormatException e) {
            return -1f;
        }
    }

    static float batteryTemp(Context c) {
        try {
            Intent i = c.registerReceiver(null, new IntentFilter(Intent.ACTION_BATTERY_CHANGED));
            if (i == null) return Float.NaN;
            int t = i.getIntExtra(BatteryManager.EXTRA_TEMPERATURE, Integer.MIN_VALUE);
            return t == Integer.MIN_VALUE ? Float.NaN : t / 10f;
        } catch (RuntimeException e) {
            return Float.NaN;
        }
    }

    static String soc() {
        if (Build.VERSION.SDK_INT >= 31) {
            String m = Build.SOC_MODEL;
            if (m != null && !m.isEmpty() && !Build.UNKNOWN.equals(m)) {
                String mf = Build.SOC_MANUFACTURER;
                return (mf != null && !Build.UNKNOWN.equals(mf) ? mf + " " : "") + m;
            }
        }
        return Build.HARDWARE == null ? "" : Build.HARDWARE;
    }

    /** Le système réserve une partie de la RAM : on ramène à la taille commerciale (7,4 Gio → 8 Go). */
    static int nominalGb(long totalBytes) {
        if (totalBytes <= 0) return 0;
        double gib = totalBytes / (1024.0 * 1024 * 1024);
        int[] sizes = {1, 2, 3, 4, 6, 8, 10, 12, 16, 18, 20, 24, 32};
        for (int sz : sizes) if (gib <= sz + 0.01) return sz;
        return (int) Math.ceil(gib);
    }

    private static String cap(String s) {
        if (s == null || s.isEmpty()) return "";
        return Character.toUpperCase(s.charAt(0)) + s.substring(1);
    }

    // ------------------------------------------------------------------ constats

    static List<Finding> findings(Snapshot s) {
        List<Finding> out = new ArrayList<>();
        double free = s.freePct();

        // Stockage : la mémoire flash ralentit quand elle est presque pleine.
        if (s.storageTotal > 0) {
            String amount = Fmt.bytes(s.storageFree) + " libres sur " + Fmt.bytes(s.storageTotal) + " (" + Fmt.pct(free) + ").";
            if (free < 10) {
                out.add(new Finding(Ui.URGENT, "Stockage presque plein", amount
                        + " En dessous d'environ 10 %, les écritures ralentissent, les mises à jour peuvent échouer"
                        + " et le système n'a plus de marge pour sa maintenance. C'est la priorité.",
                        "Libérer de l'espace", ACT_TAB_CLEAN));
            } else if (free < 20) {
                out.add(new Finding(Ui.FIX, "Stockage un peu juste", amount
                        + " Gardez idéalement plus de 15 à 20 % libres pour que la mémoire flash reste rapide.",
                        "Voir le nettoyage utile", ACT_TAB_CLEAN));
            } else {
                out.add(new Finding(Ui.GOOD, "Espace de stockage suffisant", amount, null, ACT_NONE));
            }
        }

        // Chauffe : le processeur est bridé quand le téléphone chauffe.
        if (s.thermal >= 3) {
            out.add(new Finding(Ui.URGENT, "Le téléphone surchauffe",
                    "Le système bride fortement le processeur pour refroidir. Laissez-le refroidir (hors coque,"
                            + " hors charge rapide, à l'ombre) avant de juger ses performances.", null, ACT_NONE));
        } else if (s.thermal == 2) {
            out.add(new Finding(Ui.FIX, "Le téléphone chauffe",
                    "Bridage modéré du processeur en cours : saccades probables tant qu'il ne refroidit pas.",
                    null, ACT_NONE));
        } else if (s.thermal == 1 || (!Float.isNaN(s.batteryTemp) && s.batteryTemp >= 42f)) {
            String t = Float.isNaN(s.batteryTemp) ? "" : " Batterie à " + Fmt.num1(s.batteryTemp) + NBSP_C + ".";
            out.add(new Finding(Ui.INFO, "Le téléphone est tiède",
                    "Léger bridage possible. Une coque épaisse ou la charge rapide pendant l'utilisation"
                            + " font chauffer et ralentissent le téléphone." + t, null, ACT_NONE));
        }

        // Économie d'énergie : bride volontairement les performances.
        if (s.powerSave) {
            out.add(new Finding(Ui.FIX, "Économie d'énergie activée",
                    "Ce mode bride le processeur et souvent la fréquence de l'écran : le téléphone paraît plus lent."
                            + " À réserver aux moments où la batterie est basse.",
                    "Réglages de batterie", ACT_BATTERY));
        }

        // Animations : réactivité perçue (pas fluidité).
        float maxAnim = Math.max(s.animWindow, Math.max(s.animTransition, s.animAnimator));
        float minAnim = Math.min(s.animWindow, Math.min(s.animTransition, s.animAnimator));
        String anims = "Fenêtres " + Fmt.scale(s.animWindow) + ", transitions " + Fmt.scale(s.animTransition)
                + ", animations " + Fmt.scale(s.animAnimator) + ".";
        if (maxAnim > 1.01f) {
            out.add(new Finding(Ui.FIX, "Animations ralenties", anims
                    + " Elles sont plus lentes que la normale : revenez au moins à 1x, idéalement 0,5x.",
                    "Régler les animations", ACT_TAB_TWEAKS));
        } else if (maxAnim <= 0.01f) {
            out.add(new Finding(Ui.INFO, "Animations désactivées", anims
                    + " Tout est instantané, mais on perd les repères visuels et certaines applis affichent mal"
                    + " leurs indicateurs de chargement. 0,5x est un meilleur compromis.",
                    "Régler les animations", ACT_TAB_TWEAKS));
        } else if (minAnim > 0.75f) {
            out.add(new Finding(Ui.FIX, "Animations à vitesse standard", anims
                    + " Passer à 0,5x rend l'interface nettement plus réactive (c'est un ressenti : les images"
                    + " ne sont pas plus régulières pour autant).",
                    "Passer à 0,5x", ACT_TAB_TWEAKS));
        } else {
            out.add(new Finding(Ui.GOOD, "Animations accélérées", anims, null, ACT_NONE));
        }

        // Écran.
        if (s.refreshMax <= 61f) {
            String other = s.refreshMaxAnyRes > 61f
                    ? " (" + Fmt.hz(s.refreshMaxAnyRes) + " existe, mais seulement à une autre définition d'écran : voyez Paramètres › Affichage.)"
                    : "";
            out.add(new Finding(other.isEmpty() ? Ui.LIMIT : Ui.FIX, "Écran limité à 60 Hz",
                    "C'est une caractéristique matérielle." + other + " À noter : les iPhone non-Pro sont restés"
                            + " à 60 Hz jusqu'à l'iPhone 16. La régularité des images compte davantage.",
                    other.isEmpty() ? null : "Réglages d'affichage", other.isEmpty() ? ACT_NONE : ACT_DISPLAY));
        } else if (s.peakSetting > 0 && s.peakSetting < s.refreshMax - 1f) {
            out.add(new Finding(Ui.FIX, "Écran bridé à " + Fmt.hz(s.peakSetting),
                    "Votre écran peut afficher jusqu'à " + Fmt.hz(s.refreshMax) + " mais le réglage le limite."
                            + " Activez la fréquence élevée (ou adaptative) dans les réglages d'affichage.",
                    "Réglages d'affichage", ACT_DISPLAY));
        } else if (s.peakSetting >= s.refreshMax - 1f) {
            out.add(new Finding(Ui.GOOD, "Écran " + Fmt.hz(s.refreshMax) + " activé",
                    "La fréquence élevée est autorisée. Le test de l'onglet Mesure indique la fréquence réellement"
                            + " utilisée pendant un défilement.", null, ACT_NONE));
        } else {
            out.add(new Finding(Ui.INFO, "Écran jusqu'à " + Fmt.hz(s.refreshMax),
                    "Vérifiez que la fréquence élevée ou adaptative est activée (selon la marque : « Fluidité des"
                            + " mouvements », « Taux de rafraîchissement », « Affichage fluide »). Le test de l'onglet"
                            + " Mesure indique la fréquence réellement utilisée pendant un défilement.",
                    "Réglages d'affichage", ACT_DISPLAY));
        }

        // Mémoire vive : limite matérielle, aucune appli n'en ajoute.
        if (s.lowRamDevice) {
            out.add(new Finding(Ui.LIMIT, "Appareil « Android Go »",
                    "Version allégée d'Android pour téléphones à très peu de mémoire. Préférez les applis « Lite »/« Go »"
                            + " et gardez peu d'applis installées.", null, ACT_NONE));
        } else if (s.ramNominalGb > 0 && s.ramNominalGb <= 3) {
            out.add(new Finding(Ui.LIMIT, "Mémoire vive : " + s.ramNominalGb + " Go",
                    "Trop peu pour un Android récent : les applis se relancent souvent depuis zéro. Aucune appli ne peut"
                            + " ajouter de la RAM ; limitez le nombre d'applis et préférez les versions « Lite ».",
                    null, ACT_NONE));
        } else if (s.ramNominalGb == 4) {
            out.add(new Finding(Ui.LIMIT, "Mémoire vive : 4 Go",
                    "Juste pour un Android récent : le passage d'une appli à l'autre recharge souvent les applis."
                            + " Désinstaller ce qui ne sert pas aide un peu ; le reste est matériel.",
                    null, ACT_NONE));
        } else if (s.ramNominalGb >= 6) {
            out.add(new Finding(Ui.GOOD, "Mémoire vive : " + s.ramNominalGb + " Go",
                    "Suffisant. Ne cherchez pas à « libérer » la RAM : Android l'utilise volontairement pour"
                            + " rouvrir vos applis instantanément.", null, ACT_NONE));
        }

        // Version d'Android : depuis Android 12, le moteur ART est mis à jour via le Play Store.
        if (s.sdk < 31) {
            out.add(new Finding(Ui.LIMIT, "Android " + s.release + " (ancien)",
                    "Depuis Android 12, le moteur qui exécute les applis (ART) reçoit des améliorations de vitesse"
                            + " par les mises à jour système Google Play. Votre version n'en profite plus.",
                    null, ACT_NONE));
        }

        // Applis préinstallées connues.
        if (!s.bloat.isEmpty()) {
            StringBuilder names = new StringBuilder();
            for (int i = 0; i < s.bloat.size() && i < 3; i++) {
                if (i > 0) names.append(", ");
                names.append(s.bloat.get(i).name);
            }
            if (s.bloat.size() > 3) names.append("…");
            out.add(new Finding(Ui.FIX, s.bloat.size() + (s.bloat.size() > 1
                    ? " applis préinstallées inutiles actives" : " appli préinstallée inutile active"),
                    names + ". Elles tournent en arrière-plan sans que vous les utilisiez ; les désactiver est réversible.",
                    "Voir comment les désactiver", ACT_TAB_TWEAKS));
        }

        // Redémarrage : effet réel mais modeste.
        if (s.uptimeMs > 7 * Fmt.DAY) {
            out.add(new Finding(Ui.INFO, "Allumé depuis " + Fmt.duration(s.uptimeMs),
                    "Un redémarrage par semaine élimine les fuites de mémoire et processus bloqués de certaines"
                            + " applis. Effet réel mais modeste : inutile de redémarrer tous les jours.",
                    null, ACT_NONE));
        }

        if (s.userApps > 0) {
            out.add(new Finding(Ui.INFO, s.userApps + " applis installées par vous",
                    "Le nombre compte moins que ce qu'elles font en arrière-plan. Repérez celles que vous n'ouvrez"
                            + " plus : chacune peut se réveiller, se mettre à jour et occuper de la mémoire.",
                    "Voir les applis inutilisées", ACT_TAB_CLEAN));
        }

        // Tri : urgent, à corriger, limite, conseil, OK (tri stable).
        List<Finding> sorted = new ArrayList<>(out.size());
        int[] order = {Ui.URGENT, Ui.FIX, Ui.LIMIT, Ui.INFO, Ui.GOOD};
        for (int lv : order) for (Finding f : out) if (f.level == lv) sorted.add(f);
        return sorted;
    }

    private static final String NBSP_C = Fmt.NBSP + "°C";
}
