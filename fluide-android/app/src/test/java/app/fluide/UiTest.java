package app.fluide;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;
import static org.robolectric.Shadows.shadowOf;

import android.app.ActivityManager;
import android.app.AppOpsManager;
import android.app.Application;
import android.app.usage.StorageStats;
import android.app.usage.StorageStatsManager;
import android.app.usage.UsageStatsManager;
import android.content.ComponentName;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.hardware.display.DisplayManager;
import android.os.BatteryManager;
import android.os.Build;
import android.os.Environment;
import android.os.Looper;
import android.os.PowerManager;
import android.os.Process;
import android.os.storage.StorageManager;
import android.provider.Settings;
import android.view.Display;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ListView;
import android.widget.ScrollView;
import android.widget.TextView;

import org.robolectric.RuntimeEnvironment;

import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;
import org.robolectric.annotation.GraphicsMode;
import org.robolectric.shadows.ShadowBuild;
import org.robolectric.shadows.ShadowDisplayManager;
import org.robolectric.shadows.ShadowStatFs;
import org.robolectric.shadows.ShadowSystemClock;
import org.robolectric.shadows.ShadowUsageStatsManager;

import java.io.File;
import java.io.FileOutputStream;
import java.lang.reflect.Field;
import java.time.Duration;
import java.util.ArrayList;
import java.util.List;

/**
 * L'appli sur un « faux téléphone » (Robolectric) : Android 8.0 (minimum) et Android 16 (cible).
 * Téléphone simulé : 4 Go de RAM, stockage presque plein, écran 120 Hz, animations à 1x,
 * deux applis préinstallées connues, deux applis utilisateur dont une inutilisée.
 */
@RunWith(RobolectricTestRunner.class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = {26, 36}, qualifiers = "w393dp-h852dp-xxhdpi")
public class UiTest {
    private static final long GB = 1_000_000_000L;
    private Application app;

    @Before
    public void fakePhone() throws Exception {
        app = RuntimeEnvironment.getApplication();
        ShadowBuild.setManufacturer("samsung");
        ShadowBuild.setModel("Galaxy A15");

        ActivityManager.MemoryInfo mi = new ActivityManager.MemoryInfo();
        mi.totalMem = 3_850_000_000L;
        mi.availMem = 1_100_000_000L;
        shadowOf(app.getSystemService(ActivityManager.class)).setMemoryInfo(mi);

        // 128 Go dont 7 % libres (blocs de 4 096 octets).
        int total = (int) (128 * GB / 4096);
        int free = (int) (9 * GB / 4096);
        ShadowStatFs.registerStats(Environment.getDataDirectory(), total, free, free);

        // Écran 60/120 Hz à la définition actuelle de l'écran simulé.
        Display.Mode cur = app.getSystemService(DisplayManager.class).getDisplay(Display.DEFAULT_DISPLAY).getMode();
        ShadowDisplayManager.setSupportedModes(Display.DEFAULT_DISPLAY,
                ShadowDisplayManager.ModeBuilder.modeBuilder(cur.getModeId()).setWidth(cur.getPhysicalWidth())
                        .setHeight(cur.getPhysicalHeight()).setRefreshRate(60f).build(),
                ShadowDisplayManager.ModeBuilder.modeBuilder(cur.getModeId() + 1).setWidth(cur.getPhysicalWidth())
                        .setHeight(cur.getPhysicalHeight()).setRefreshRate(120f).build());

        Settings.Global.putFloat(app.getContentResolver(), Settings.Global.WINDOW_ANIMATION_SCALE, 1f);
        Settings.Global.putFloat(app.getContentResolver(), Settings.Global.TRANSITION_ANIMATION_SCALE, 1f);
        Settings.Global.putFloat(app.getContentResolver(), Settings.Global.ANIMATOR_DURATION_SCALE, 1f);

        PowerManager pm = app.getSystemService(PowerManager.class);
        shadowOf(pm).setIsPowerSaveMode(false);
        if (Build.VERSION.SDK_INT >= 29) shadowOf(pm).setCurrentThermalStatus(PowerManager.THERMAL_STATUS_NONE);
        app.sendStickyBroadcast(new Intent(Intent.ACTION_BATTERY_CHANGED).putExtra(BatteryManager.EXTRA_TEMPERATURE, 312));
        ShadowSystemClock.advanceBy(Duration.ofDays(9));

        long now = System.currentTimeMillis();
        install("com.facebook.appmanager", "Facebook App Manager", true, now - 400 * Fmt.DAY, 0);
        install("com.samsung.android.app.tips", "Astuces", true, now - 400 * Fmt.DAY, 0);
        install("com.exemple.jeu", "Jeu de course", false, now - 200 * Fmt.DAY, 2_400_000_000L);
        install("com.exemple.notes", "Notes", false, now - 300 * Fmt.DAY, 80_000_000L);
        // Notes ouverte hier ; le jeu, il y a 94 jours.
        ShadowUsageStatsManager usm = shadowOf(app.getSystemService(UsageStatsManager.class));
        usm.addUsageStats(UsageStatsManager.INTERVAL_BEST, usage("com.exemple.notes", now - Fmt.DAY));
        usm.addUsageStats(UsageStatsManager.INTERVAL_BEST, usage("com.exemple.jeu", now - 94 * Fmt.DAY));
    }

    private android.app.usage.UsageStats usage(String pkg, long last) {
        ShadowUsageStatsManager.UsageStatsBuilder b = ShadowUsageStatsManager.UsageStatsBuilder.newBuilder()
                .setPackageName(pkg)
                .setFirstTimeStamp(last - Fmt.DAY)
                .setLastTimeStamp(last)
                .setLastTimeUsed(last);
        if (Build.VERSION.SDK_INT >= 29) b.setLastTimeVisible(last); // champ apparu avec Android 10
        return b.build();
    }

    private void install(String pkg, String label, boolean system, long installed, long size) throws Exception {
        PackageInfo pi = new PackageInfo();
        pi.packageName = pkg;
        pi.firstInstallTime = installed;
        pi.applicationInfo = new ApplicationInfo();
        pi.applicationInfo.packageName = pkg;
        pi.applicationInfo.nonLocalizedLabel = label;
        pi.applicationInfo.enabled = true;
        pi.applicationInfo.storageUuid = StorageManager.UUID_DEFAULT;
        if (system) pi.applicationInfo.flags |= ApplicationInfo.FLAG_SYSTEM;
        shadowOf(app.getPackageManager()).installPackage(pi);
        ComponentName cn = new ComponentName(pkg, pkg + ".Main");
        shadowOf(app.getPackageManager()).addActivityIfNotPresent(cn);
        IntentFilter f = new IntentFilter(Intent.ACTION_MAIN);
        f.addCategory(Intent.CATEGORY_LAUNCHER);
        shadowOf(app.getPackageManager()).addIntentFilterForActivity(cn, f);
        if (size > 0) {
            StorageStats st = StorageStats.class.getDeclaredConstructor().newInstance();
            setField(st, "codeBytes", size / 4);
            setField(st, "dataBytes", size - size / 4);
            shadowOf(app.getSystemService(StorageStatsManager.class))
                    .addStorageStats(StorageManager.UUID_DEFAULT, pkg, Process.myUserHandle(), st);
        }
    }

    private static void setField(Object o, String name, long v) throws Exception {
        Field f = o.getClass().getDeclaredField(name);
        f.setAccessible(true);
        f.setLong(o, v);
    }

    private void grantUsageAccess() {
        shadowOf(app.getSystemService(AppOpsManager.class)).setMode(AppOpsManager.OPSTR_GET_USAGE_STATS,
                Process.myUid(), app.getPackageName(), AppOpsManager.MODE_ALLOWED);
    }

    // ---------------------------------------------------------------- outils

    private static void settle(MainActivity a) {
        for (int i = 0; i < 4; i++) {
            assertTrue("tâche en arrière-plan trop longue", a.awaitBackground(20_000));
            shadowOf(Looper.getMainLooper()).idle();
        }
    }

    private static void texts(View v, List<String> out) {
        if (v instanceof TextView) out.add(((TextView) v).getText().toString());
        if (v instanceof ViewGroup) {
            ViewGroup g = (ViewGroup) v;
            for (int i = 0; i < g.getChildCount(); i++) texts(g.getChildAt(i), out);
        }
    }

    private static String allText(MainActivity a) {
        List<String> out = new ArrayList<>();
        texts(a.getWindow().getDecorView(), out);
        return String.join("\n", out);
    }

    private static View findText(View v, String text) {
        if (v instanceof TextView && ((TextView) v).getText().toString().equals(text)) return v;
        if (v instanceof ViewGroup) {
            ViewGroup g = (ViewGroup) v;
            for (int i = 0; i < g.getChildCount(); i++) {
                View r = findText(g.getChildAt(i), text);
                if (r != null) return r;
            }
        }
        return null;
    }

    private static void click(MainActivity a, String text) {
        View v = findText(a.getWindow().getDecorView(), text);
        assertNotNull("bouton introuvable : " + text, v);
        assertTrue("bouton inactif : " + text, v.isEnabled());
        v.performClick();
        settle(a);
    }

    private static ScrollView scrollOf(View v) {
        if (v instanceof ScrollView) return (ScrollView) v;
        if (v instanceof ViewGroup) {
            ViewGroup g = (ViewGroup) v;
            for (int i = 0; i < g.getChildCount(); i++) {
                ScrollView s = scrollOf(g.getChildAt(i));
                if (s != null) return s;
            }
        }
        return null;
    }

    /** Met en page tout le contenu défilant de l'onglet, sur toute sa hauteur. */
    private static View layoutContent(MainActivity a) {
        View content = scrollOf(a.getWindow().getDecorView()).getChildAt(0);
        int w = content.getWidth() > 0 ? content.getWidth() : 1179;
        content.measure(View.MeasureSpec.makeMeasureSpec(w, View.MeasureSpec.EXACTLY),
                View.MeasureSpec.makeMeasureSpec(0, View.MeasureSpec.UNSPECIFIED));
        content.layout(0, 0, w, content.getMeasuredHeight());
        return content;
    }

    /** Aucun bouton ne doit déborder de son conteneur (il serait rogné à l'écran). */
    private static int assertButtonsNotClipped(View v) {
        int n = 0;
        if (v instanceof ViewGroup) {
            ViewGroup g = (ViewGroup) v;
            for (int i = 0; i < g.getChildCount(); i++) {
                View c = g.getChildAt(i);
                if (c instanceof android.widget.Button && c.getVisibility() == View.VISIBLE) {
                    n++;
                    assertTrue("bouton rogné : « " + ((TextView) c).getText() + " » (bas " + c.getBottom()
                            + " > hauteur " + g.getHeight() + ")", c.getTop() >= 0 && c.getBottom() <= g.getHeight());
                }
                n += assertButtonsNotClipped(c);
            }
        }
        return n;
    }

    /** Capture de l'onglet entier (tout le contenu défilant) pour relecture visuelle. */
    private static void screenshot(MainActivity a, String name) throws Exception {
        String dir = System.getProperty("fluide.screenshots");
        if (dir == null || Build.VERSION.SDK_INT < 36) return;
        View content = layoutContent(a);
        int w = content.getWidth();
        int h = content.getHeight();
        Bitmap b = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888);
        Canvas c = new Canvas(b);
        c.drawColor(a.ui.bg);
        content.draw(c);
        new File(dir).mkdirs();
        try (FileOutputStream out = new FileOutputStream(new File(dir, name + ".png"))) {
            b.compress(Bitmap.CompressFormat.PNG, 100, out);
        }
        // Écran complet (en-tête et barre d'onglets) tel que vu sur le téléphone.
        View decor = a.getWindow().getDecorView();
        Bitmap s = Bitmap.createBitmap(decor.getWidth(), decor.getHeight(), Bitmap.Config.ARGB_8888);
        decor.draw(new Canvas(s));
        try (FileOutputStream out = new FileOutputStream(new File(dir, name + "-ecran.png"))) {
            s.compress(Bitmap.CompressFormat.PNG, 100, out);
        }
    }

    private MainActivity launch() {
        ActivityController<MainActivity> c = Robolectric.buildActivity(MainActivity.class).setup();
        MainActivity a = c.get();
        settle(a);
        return a;
    }

    // ---------------------------------------------------------------- tests

    @Test
    public void analyseShowsPrioritizedFindings() throws Exception {
        MainActivity a = launch();
        String t = allText(a);
        assertTrue(t.contains("Samsung Galaxy A15"));
        assertTrue(t.contains("Stockage presque plein"));       // 7 % libres : urgent
        assertTrue(t.contains("Mémoire vive : 4 Go"));          // limite matérielle
        assertTrue(t.contains("Animations à vitesse standard")); // à corriger
        assertTrue(t.contains("2 applis préinstallées inutiles actives"));
        assertTrue(t.contains("Allumé depuis 9"));
        assertTrue(t.contains("jusqu'à 120"));
        assertFalse(t.contains("L'analyse a échoué"));
        screenshot(a, "1-analyse");
    }

    @Test
    public void everyTabRendersAndActionsNavigate() throws Exception {
        grantUsageAccess(); // pour que la liste d'applis (et ses boutons) s'affiche
        MainActivity a = launch();
        click(a, "Libérer de l'espace");
        assertEquals(MainActivity.TAB_NETTOYAGE, a.currentTab());
        int buttons = 0;
        for (int tab = 0; tab < MainActivity.TAB_NAMES.length; tab++) {
            a.showTab(tab);
            settle(a);
            assertEquals(tab, a.currentTab());
            assertTrue(allText(a).length() > 200);
            buttons += assertButtonsNotClipped(layoutContent(a));
        }
        assertTrue("trop peu de boutons vérifiés : " + buttons, buttons >= 12);
    }

    @Test
    public void introIsShownOnceThenDismissed() {
        MainActivity a = launch();
        String t = allText(a);
        assertTrue(t.contains("Ce que Fluide fait, et ne fait pas"));
        if (Build.VERSION.SDK_INT >= 31) {
            // Une seule limite (4 Go de RAM) : accord au singulier.
            assertTrue(t.contains("1 limite matérielle : aucune appli ne peut la corriger, mais elle explique"));
        } else {
            // Plus Android trop ancien pour les mises à jour d'ART : pluriel.
            assertTrue(t.contains("2 limites matérielles : aucune appli ne peut les corriger, mais elles expliquent"));
        }
        click(a, "J'ai compris");
        assertFalse(allText(a).contains("Ce que Fluide fait, et ne fait pas"));
        MainActivity again = launch();
        assertFalse(allText(again).contains("Ce que Fluide fait, et ne fait pas"));
        assertTrue(allText(again).contains("Bilan"));
    }

    @Test
    public void reglagesWithoutPermissionExplainsAdb() throws Exception {
        MainActivity a = launch();
        a.showTab(MainActivity.TAB_REGLAGES);
        settle(a);
        String t = allText(a);
        assertTrue(t.contains("adb shell pm grant app.fluide android.permission.WRITE_SECURE_SETTINGS"));
        assertTrue(t.contains("settings put system peak_refresh_rate 120.0"));
        assertTrue(t.contains(Build.VERSION.SDK_INT >= 34 ? "pm art dexopt-packages -r bg-dexopt"
                : "cmd package bg-dexopt-job"));
        assertTrue(t.contains("compile -m speed-profile -a"));
        assertTrue(t.contains("Facebook App Manager"));
        View preset = findText(a.getWindow().getDecorView(), "0,5x\nRecommandé");
        assertNotNull(preset);
        assertFalse("préréglage actif sans autorisation", preset.isEnabled());
        screenshot(a, "2-reglages");
    }

    @Test
    public void animationPresetsApplyWithPermission() {
        shadowOf(app).grantPermissions(SystemTweaks.PERM);
        MainActivity a = launch();
        a.showTab(MainActivity.TAB_REGLAGES);
        settle(a);
        assertTrue(allText(a).contains("Accordée"));
        click(a, "0,5x\nRecommandé");
        float[] v = SystemTweaks.readAnim(app.getContentResolver());
        assertEquals(0.5f, v[0], 0.001f);
        assertEquals(0.5f, v[1], 0.001f);
        assertEquals(0.5f, v[2], 0.001f);
        assertTrue(allText(a).contains("fenêtres 0,5x"));
        click(a, "1x\nStandard");
        assertEquals(1f, SystemTweaks.readAnim(app.getContentResolver())[2], 0.001f);
    }

    @Test
    public void nettoyageListsUnusedAppsWithUsageAccess() throws Exception {
        grantUsageAccess();
        MainActivity a = launch();
        a.showTab(MainActivity.TAB_NETTOYAGE);
        settle(a);
        String t = allText(a);
        assertTrue(t, t.contains("1 appli pas ouverte depuis 30 jours ou plus"));
        assertTrue(t.contains("Jeu de course"));
        assertTrue(t.contains("Ouverte il y a 3 mois")); // 94 jours
        assertFalse("une appli utilisée hier n'est pas « inutilisée »", t.contains("Notes"));
        assertFalse("les applis système ne sont pas proposées à la désinstallation", t.contains("Astuces"));
        screenshot(a, "3-nettoyage");
        click(a, "Toutes, par taille");
        assertTrue(allText(a).contains("Notes"));
    }

    @Test
    public void nettoyageAsksForUsageAccessFirst() {
        MainActivity a = launch();
        a.showTab(MainActivity.TAB_NETTOYAGE);
        settle(a);
        assertTrue(allText(a).contains("Autoriser l'accès"));
    }

    @Test
    public void mesureShowsHistoryAndFairComparison() throws Exception {
        ResultStore.Entry before = new ResultStore.Entry();
        before.time = System.currentTimeMillis() - 3600_000;
        before.frames = 958;
        before.latePct = 9.4;
        before.fps = 108;
        before.hz = 120;
        before.p95 = 11.2;
        before.budgetMs = 8.3;
        before.deadlineBased = true;
        before.temp = 33f;
        ResultStore.add(app, before);
        ResultStore.Entry after = new ResultStore.Entry();
        after.time = System.currentTimeMillis();
        after.frames = 960;
        after.latePct = 2.1;
        after.fps = 118;
        after.hz = 120;
        after.p95 = 7.4;
        after.budgetMs = 8.3;
        after.deadlineBased = true;
        after.anim = 0.5f;
        after.temp = 32f;
        ResultStore.add(app, after);

        MainActivity a = launch();
        a.showTab(MainActivity.TAB_MESURE);
        settle(a);
        String t = allText(a);
        assertTrue(t.contains("Très bon"));
        assertTrue(t.contains("Mieux que la mesure précédente"));
        assertTrue(t.contains("7,3 points de moins"));
        assertTrue(t.contains("Historique"));
        screenshot(a, "4-mesure");
    }

    @Test
    public void vraiFauxListsAllMyths() throws Exception {
        MainActivity a = launch();
        a.showTab(MainActivity.TAB_VRAI_FAUX);
        settle(a);
        String t = allText(a);
        for (TabVraiFaux.Myth m : TabVraiFaux.MYTHS) assertTrue(m.claim, t.contains(m.claim));
        screenshot(a, "5-vrai-faux");
    }

    @Test
    @Config(qualifiers = "+night")
    public void darkThemeRenders() throws Exception {
        MainActivity a = launch();
        assertEquals(app.getColor(R.color.bg), a.ui.bg);
        screenshot(a, "6-analyse-sombre");
    }

    @Test
    public void frameTestActivityBuildsFeedAndRecordsResult() {
        ActivityController<FrameTestActivity> c = Robolectric.buildActivity(FrameTestActivity.class).setup();
        FrameTestActivity t = c.get();
        ListView list = (ListView) findListView(t.getWindow().getDecorView());
        assertNotNull(list);
        // Les deux types de cartes (texte, image) se construisent et se recyclent.
        View text = list.getAdapter().getView(0, null, list);
        View media = list.getAdapter().getView(2, null, list);
        assertNotNull(text);
        assertNotNull(media);
        assertEquals(2, list.getAdapter().getViewTypeCount());
        assertTrue(list.getAdapter().getView(4, text, list) == text);

        FrameStats fs = new FrameStats();
        for (int i = 0; i < 100; i++) fs.add(6_000_000L, 8_333_333L, i * 8_333_333L);
        ResultStore.Entry e = t.toEntry(fs.summarize(120), 120);
        assertEquals(100, e.frames);
        assertEquals(0.0, e.latePct, 1e-9);
        assertEquals(1f, e.anim, 0.001f);

        // Quitter pendant la mesure l'annule proprement.
        c.pause();
        assertTrue(t.isFinishing());
    }

    private static View findListView(View v) {
        if (v instanceof ListView) return v;
        if (v instanceof ViewGroup) {
            ViewGroup g = (ViewGroup) v;
            for (int i = 0; i < g.getChildCount(); i++) {
                View r = findListView(g.getChildAt(i));
                if (r != null) return r;
            }
        }
        return null;
    }

    @Test
    public void bloatDetectionIgnoresDisabledApps() {
        PackageManager pm = app.getPackageManager();
        assertEquals(2, Bloat.findInstalled(pm).size());
        pm.setApplicationEnabledSetting("com.facebook.appmanager",
                PackageManager.COMPONENT_ENABLED_STATE_DISABLED_USER, 0);
        List<Bloat.Entry> left = Bloat.findInstalled(pm);
        assertEquals(1, left.size());
        assertEquals("com.samsung.android.app.tips", left.get(0).pkg);
    }
}
