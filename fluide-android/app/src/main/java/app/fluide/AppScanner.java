package app.fluide;

import android.app.AppOpsManager;
import android.app.usage.StorageStats;
import android.app.usage.StorageStatsManager;
import android.app.usage.UsageStats;
import android.app.usage.UsageStatsManager;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.content.pm.ResolveInfo;
import android.graphics.drawable.Drawable;
import android.os.Build;
import android.os.Process;
import android.provider.Settings;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Map;

/** Applis installées par l'utilisateur : dernière ouverture et place occupée. */
final class AppScanner {
    static final int UNUSED_DAYS = 30;

    static final class AppItem {
        String pkg;
        CharSequence label;
        Drawable icon;
        long lastUsed;      // 0 : aucune ouverture enregistrée
        long installed;
        long sizeBytes = -1;

        /** Jours depuis la dernière ouverture, ou depuis l'installation si aucune n'est connue. */
        long idleDays(long now) {
            long ref = lastUsed > 0 ? lastUsed : installed;
            return Math.max(0, (now - ref) / Fmt.DAY);
        }

        boolean unused(long now) {
            return idleDays(now) >= UNUSED_DAYS;
        }
    }

    private AppScanner() {}

    @SuppressWarnings("deprecation")
    static boolean hasUsageAccess(Context c) {
        AppOpsManager aom = c.getSystemService(AppOpsManager.class);
        if (aom == null) return false;
        int mode = Build.VERSION.SDK_INT >= 29
                ? aom.unsafeCheckOpNoThrow(AppOpsManager.OPSTR_GET_USAGE_STATS, Process.myUid(), c.getPackageName())
                : aom.checkOpNoThrow(AppOpsManager.OPSTR_GET_USAGE_STATS, Process.myUid(), c.getPackageName());
        if (mode == AppOpsManager.MODE_DEFAULT) {
            return c.checkSelfPermission(android.Manifest.permission.PACKAGE_USAGE_STATS)
                    == PackageManager.PERMISSION_GRANTED;
        }
        return mode == AppOpsManager.MODE_ALLOWED;
    }

    /** Lent (une requête de taille par appli) : à appeler hors du fil principal. */
    static List<AppItem> scan(Context c) {
        PackageManager pm = c.getPackageManager();
        long now = System.currentTimeMillis();
        Map<String, UsageStats> usage = Collections.emptyMap();
        UsageStatsManager usm = c.getSystemService(UsageStatsManager.class);
        if (usm != null) {
            Map<String, UsageStats> m = usm.queryAndAggregateUsageStats(now - 365L * Fmt.DAY, now);
            if (m != null) usage = m;
        }
        StorageStatsManager ssm = c.getSystemService(StorageStatsManager.class);
        String keyboard = keyboardPackage(c);
        String home = homePackage(pm);

        List<AppItem> out = new ArrayList<>();
        for (ApplicationInfo ai : pm.getInstalledApplications(0)) {
            String pkg = ai.packageName;
            if ((ai.flags & ApplicationInfo.FLAG_SYSTEM) != 0 || !ai.enabled) continue;
            if (pkg.equals(c.getPackageName()) || pkg.equals(keyboard) || pkg.equals(home)) continue;
            // Sans icône d'accueil, une appli sert souvent sans être « ouverte » (extension, service…).
            if (pm.getLaunchIntentForPackage(pkg) == null) continue;

            AppItem it = new AppItem();
            it.pkg = pkg;
            it.label = ai.loadLabel(pm);
            it.icon = ai.loadIcon(pm);
            UsageStats us = usage.get(pkg);
            if (us != null) {
                long last = us.getLastTimeUsed();
                if (Build.VERSION.SDK_INT >= 29) last = Math.max(last, us.getLastTimeVisible());
                // Valeurs aberrantes possibles (horloge, restauration) : on les ignore.
                it.lastUsed = last > 0 && last <= now ? last : 0;
            }
            try {
                PackageInfo pi = pm.getPackageInfo(pkg, 0);
                it.installed = pi.firstInstallTime;
            } catch (PackageManager.NameNotFoundException e) {
                continue;
            }
            if (ssm != null) {
                try {
                    StorageStats st = ssm.queryStatsForPackage(ai.storageUuid, pkg, Process.myUserHandle());
                    it.sizeBytes = st.getAppBytes() + st.getDataBytes();
                } catch (Exception ignored) {
                    // taille inconnue (droits, stockage externe retiré…)
                }
            }
            out.add(it);
        }
        return out;
    }

    static String keyboardPackage(Context c) {
        String id = Settings.Secure.getString(c.getContentResolver(), Settings.Secure.DEFAULT_INPUT_METHOD);
        if (id == null) return "";
        ComponentName cn = ComponentName.unflattenFromString(id);
        return cn == null ? "" : cn.getPackageName();
    }

    static String homePackage(PackageManager pm) {
        Intent i = new Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME);
        ResolveInfo ri = pm.resolveActivity(i, PackageManager.MATCH_DEFAULT_ONLY);
        return ri == null || ri.activityInfo == null ? "" : ri.activityInfo.packageName;
    }
}
