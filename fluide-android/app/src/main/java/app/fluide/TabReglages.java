package app.fluide;

import android.provider.Settings;
import android.widget.LinearLayout;

import java.util.List;

/** Onglet « Réglages » : ce qui se règle vraiment, et comment. */
final class TabReglages {
    private TabReglages() {}

    static void build(MainActivity a, LinearLayout root) {
        Ui ui = a.ui;
        boolean can = SystemTweaks.canWrite(a);

        // 1. Autorisation ADB
        LinearLayout c = ui.card(root);
        ui.title(c, "Autorisation « réglages système »");
        if (can) {
            ui.status(c, Ui.GOOD, "Accordée : Fluide peut régler la vitesse des animations.");
        } else {
            ui.body(c, "Android réserve ce réglage aux outils de développement : il faut l'autoriser une seule"
                    + " fois depuis un ordinateur (câble USB, ou débogage sans fil depuis Android 11).");
            String cmd = SystemTweaks.grantCommand(a);
            ui.code(c, cmd);
            a.commandActions(c, "autorisation", cmd);
            ui.small(c, "1. Paramètres › À propos du téléphone : touchez 7 fois « Numéro de build ».\n"
                    + "2. Options pour les développeurs : activez « Débogage USB ».\n"
                    + "3. Sur le PC : lancez le script fluide.bat (option 2), ou cette commande avec les"
                    + " « SDK Platform-Tools » de Google. Acceptez l'empreinte affichée sur le téléphone.\n"
                    + "Xiaomi, Redmi, POCO : activez aussi « Débogage USB (paramètres de sécurité) ».\n"
                    + "Oppo, Realme, OnePlus : si la commande échoue, activez « Désactiver la surveillance"
                    + " des autorisations ».");
            ui.button(c, "Ouvrir les options pour les développeurs", Ui.SECONDARY, v -> a.openDevOptions());
        }

        // 2. Animations
        c = ui.card(root);
        ui.title(c, "Vitesse des animations");
        float[] anim = SystemTweaks.readAnim(a.getContentResolver());
        ui.body(c, "Actuellement : fenêtres " + Fmt.scale(anim[0]) + ", transitions " + Fmt.scale(anim[1])
                + ", animations " + Fmt.scale(anim[2]) + ".");
        ui.segmented(c, SystemTweaks.PRESET_LABELS, SystemTweaks.presetIndex(anim), can, i -> {
            if (SystemTweaks.setAnim(a, SystemTweaks.PRESETS[i])) {
                a.toast("Animations : " + SystemTweaks.PRESET_LABELS[i].replace('\n', ' '));
            } else {
                a.toast("Refusé : l'autorisation n'est pas accordée");
            }
            a.refresh();
        });
        ui.small(c, "0,5x : l'interface répond plus vite et les transitions restent lisibles. « Aucune » rend"
                + " tout instantané, mais on perd les repères visuels et certaines applis affichent mal leurs"
                + " indicateurs de chargement.\nC'est un gain de réactivité, pas de fluidité : la régularité des"
                + " images dépend du matériel. Vérifiez avec l'onglet Mesure.");
        if (!can) {
            ui.small(c, "Sans ordinateur : Options pour les développeurs › « Échelle animation fenêtres »,"
                    + " « Échelle animation transitions » et « Échelle durée animation » → 0,5x.");
        }

        // 3. Écran
        c = ui.card(root);
        ui.title(c, "Fréquence de l'écran");
        Diagnostics.Snapshot disp = new Diagnostics.Snapshot();
        Diagnostics.readDisplay(a, disp);
        int max = Math.round(disp.refreshMax);
        if (max <= 61) {
            ui.body(c, "Votre écran affiche au plus 60 images par seconde : c'est matériel, aucun réglage n'y"
                    + " change rien. Les iPhone non-Pro sont restés à 60 Hz jusqu'à l'iPhone 16 : la régularité"
                    + " des images compte davantage que leur nombre.");
        } else {
            ui.body(c, "Votre écran peut monter à " + Fmt.hz(max) + ". Vérifiez dans les réglages d'affichage"
                    + " que la fréquence élevée ou adaptative est activée (selon la marque : « Fluidité des"
                    + " mouvements », « Taux de rafraîchissement », « Affichage fluide »).");
            ui.button(c, "Ouvrir les réglages d'affichage", Ui.SECONDARY,
                    v -> a.openSettings(Settings.ACTION_DISPLAY_SETTINGS, null));
            ui.small(c, "Forcer " + Fmt.hz(max) + " en permanence coûte de l'autonomie et n'apporte rien quand"
                    + " l'image est fixe : l'adaptatif suffit. Android interdit ce forçage aux applis ; il n'est"
                    + " possible que par ADB (script PC, option 7) :");
            String cmd = SystemTweaks.refreshForceCommands(max);
            ui.code(c, cmd);
            a.commandActions(c, "fréquence d'écran", cmd);
            ui.small(c, "Pour annuler (script PC, option 8, ou ces commandes) :");
            ui.code(c, SystemTweaks.refreshResetCommands());
            ui.small(c, "Sans effet sur certaines marques (dont Samsung) qui utilisent leur propre réglage :"
                    + " passez alors par les réglages d'affichage.");
        }

        // 4. Compilation ART
        c = ui.card(root);
        ui.title(c, "Optimiser la compilation des applis");
        ui.body(c, "Après une mise à jour d'Android ou des applis, leur code tourne d'abord dans un mode moins"
                + " optimisé : c'est la cause classique des saccades « depuis la mise à jour ». Android recompile"
                + " la nuit, téléphone en charge et inactif. Pour le faire tout de suite (5 à 30 minutes,"
                + " téléphone branché) :");
        ui.code(c, SystemTweaks.COMPILE_CMD);
        a.commandActions(c, "compilation", SystemTweaks.COMPILE_CMD);
        ui.small(c, "Inutile de la répéter tous les jours : une fois après chaque grosse mise à jour suffit.");

        // 5. Applis préinstallées
        LinearLayout bloat = ui.card(root);
        ui.title(bloat, "Applis préinstallées inutiles");
        ui.small(bloat, "Recherche…");
        a.runBg(() -> Bloat.findInstalled(a.getPackageManager()), found -> showBloat(a, bloat, found));

        // 6. Énergie et arrière-plan
        c = ui.card(root);
        ui.title(c, "Économie d'énergie et arrière-plan");
        ui.body(c, "L'économie d'énergie bride le processeur et souvent l'écran : à réserver aux moments où la"
                + " batterie est basse. Pour une appli précise qui travaille trop en arrière-plan (réseaux"
                + " sociaux, jeux), réglez sa batterie sur « Restreinte » dans sa fiche, plutôt que d'utiliser"
                + " un « tueur de tâches ».");
        ui.button(c, "Réglages de la batterie", Ui.SECONDARY,
                v -> a.openSettings(Settings.ACTION_BATTERY_SAVER_SETTINGS, null));
        ui.button(c, "Liste des applications", Ui.QUIET,
                v -> a.openSettings(Settings.ACTION_MANAGE_APPLICATIONS_SETTINGS, null));

        // 7. Options à ne pas toucher
        c = ui.card(root);
        ui.title(c, "Options pour les développeurs à laisser par défaut");
        ui.bullets(c,
                "« Forcer MSAA 4x » : alourdit chaque image ; fluidité et autonomie en pâtissent.",
                "« Désactiver les superpositions matérielles » : le GPU compose tout l'écran, ce qui consomme"
                        + " plus, sans gain démontré.",
                "« Limite des processus en arrière-plan » : vos applis repartent de zéro à chaque fois.",
                "« Forcer le rendu GPU » (anciennes versions) : déjà actif par défaut dans les applis récentes.");
    }

    private static void showBloat(MainActivity a, LinearLayout card, List<Bloat.Entry> found) {
        Ui ui = a.ui;
        while (card.getChildCount() > 1) card.removeViewAt(1);
        if (found == null || found.isEmpty()) {
            ui.status(card, Ui.GOOD, "Aucune appli de notre liste n'est active sur ce téléphone.");
            ui.small(card, "La liste est volontairement courte : uniquement des applis dont la désactivation"
                    + " est sans risque et réversible.");
            return;
        }
        ui.body(card, "Elles tournent en arrière-plan sans que vous les utilisiez. Ouvrez leur fiche et touchez"
                + " « Désactiver » si le bouton est disponible.");
        for (Bloat.Entry e : found) {
            ui.divider(card);
            ui.text(card, e.name, 15, ui.text, ui.medium);
            ui.small(card, e.why);
            LinearLayout r = ui.buttonRow(card);
            ui.smallButton(r, "Ouvrir la fiche", Ui.SECONDARY, v -> a.openAppInfo(e.pkg));
        }
        ui.divider(card);
        ui.small(card, "Bouton grisé ? Le script PC (option 9) les désactive une par une, avec confirmation,"
                + " et l'option 10 les réactive.");
    }
}
