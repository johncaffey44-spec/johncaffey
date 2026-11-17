package app.fluide;

import android.content.pm.ApplicationInfo;
import android.content.pm.PackageManager;

import java.util.ArrayList;
import java.util.List;

/**
 * Liste volontairement courte d'applis préinstallées sans utilité pour la plupart des gens,
 * dont la désactivation est sans risque pour le démarrage et réversible.
 * Même liste que dans les scripts PC (scripts/fluide.sh et scripts/fluide.bat).
 */
final class Bloat {
    static final class Entry {
        final String pkg;
        final String name;
        final String why;

        Entry(String pkg, String name, String why) {
            this.pkg = pkg;
            this.name = name;
            this.why = why;
        }
    }

    static final Entry[] LIST = {
            new Entry("com.facebook.appmanager", "Facebook App Manager",
                    "Met à jour Facebook en arrière-plan, même sans compte."),
            new Entry("com.facebook.services", "Facebook Services",
                    "Services de fond préinstallés par Facebook."),
            new Entry("com.facebook.system", "Facebook App Installer",
                    "Installateur préinstallé par Facebook."),
            new Entry("com.aura.oobe.samsung.gl", "AppCloud",
                    "Propose et installe des applis sponsorisées (Galaxy A/M)."),
            new Entry("com.aura.oobe.samsung", "AppCloud",
                    "Propose et installe des applis sponsorisées."),
            new Entry("com.samsung.android.app.spage", "Samsung Free",
                    "Fil d'actualités à gauche de l'écran d'accueil."),
            new Entry("com.samsung.android.bixby.agent", "Bixby Voice",
                    "Assistant vocal Samsung, inutile si vous ne l'utilisez pas."),
            new Entry("com.samsung.android.app.tips", "Astuces Samsung",
                    "Notifications de conseils."),
            new Entry("com.samsung.android.ardrawing", "AR Doodle",
                    "Effets de réalité augmentée."),
            new Entry("com.miui.analytics", "Analytics (Xiaomi)",
                    "Collecte de statistiques d'utilisation."),
            new Entry("com.miui.msa.global", "MSA (Xiaomi)",
                    "Service de publicité du système MIUI/HyperOS."),
            new Entry("com.xiaomi.mipicks", "GetApps (Xiaomi)",
                    "Boutique d'applis Xiaomi, doublon du Play Store."),
            new Entry("com.netflix.partner.activation", "Netflix Partner Activation",
                    "Service d'activation Netflix préinstallé."),
    };

    private Bloat() {}

    /** Entrées de la liste installées ET actives sur ce téléphone. */
    static List<Entry> findInstalled(PackageManager pm) {
        List<Entry> out = new ArrayList<>();
        for (Entry e : LIST) {
            try {
                ApplicationInfo ai = pm.getApplicationInfo(e.pkg, 0);
                if (ai.enabled) out.add(e);
            } catch (PackageManager.NameNotFoundException ignored) {
                // absente ou déjà désinstallée pour cet utilisateur
            }
        }
        return out;
    }
}
