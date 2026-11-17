package app.fluide;

import android.app.AlertDialog;
import android.media.MediaScannerConnection;
import android.os.Build;
import android.os.Environment;
import android.os.StatFs;
import android.text.TextUtils;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

import java.util.ArrayList;
import java.util.List;

/** Onglet « Nettoyage » : seulement ce qui libère réellement de la place ou de l'activité. */
final class TabNettoyage {
    static final int MAX_ROWS = 50;

    private TabNettoyage() {}

    static void build(MainActivity a, LinearLayout root) {
        Ui ui = a.ui;

        // 1. Stockage
        LinearLayout c = ui.card(root);
        ui.title(c, "Stockage");
        long total = 0, free = 0;
        try {
            StatFs fs = new StatFs(Environment.getDataDirectory().getPath());
            total = fs.getTotalBytes();
            free = fs.getAvailableBytes();
        } catch (IllegalArgumentException ignored) {
            // illisible
        }
        double freePct = total > 0 ? 100.0 * free / total : 100;
        int level = freePct < 10 ? Ui.URGENT : freePct < 20 ? Ui.FIX : Ui.GOOD;
        ui.bar(c, total > 0 ? (float) (1 - (double) free / total) : 0f, ui.levelColor(level));
        ui.body(c, Fmt.bytes(free) + " libres sur " + Fmt.bytes(total) + " (" + Fmt.pct(freePct) + ")");
        ui.small(c, level == Ui.URGENT
                ? "Presque plein : c'est la priorité. Commencez par les applis inutilisées et les gros fichiers ci-dessous."
                : level == Ui.FIX
                ? "Un peu juste : visez 15 à 20 % d'espace libre pour garder une mémoire flash rapide."
                : "Assez d'espace : le stockage ne freine pas votre téléphone.");
        ui.button(c, "Libérer de l'espace (outil du système)", Ui.SECONDARY, v -> a.openFreeUpSpace());

        // 2. Applis inutilisées
        LinearLayout apps = ui.card(root);
        ui.title(apps, "Applis que vous n'ouvrez plus");
        if (!AppScanner.hasUsageAccess(a)) {
            ui.body(apps, "Pour repérer les applis qui dorment, Fluide a besoin de l'« accès aux données"
                    + " d'utilisation ». Ces informations restent sur le téléphone : Fluide n'a pas accès à Internet.");
            ui.button(apps, "Autoriser l'accès", Ui.PRIMARY, v -> a.openUsageAccess());
        } else {
            ui.small(apps, "Analyse des applis…");
            a.runBg(() -> AppScanner.scan(a), list -> showApps(a, apps, list));
        }

        // 3. Gros fichiers et APK
        LinearLayout files = ui.card(root);
        ui.title(files, "Gros fichiers et APK oubliés");
        if (!FileScanner.hasAccess(a)) {
            ui.body(files, "Vidéos oubliées, archives, fichiers d'installation (APK) restés dans Téléchargements…"
                    + " Pour les trouver, Fluide a besoin de l'accès aux fichiers. Rien n'est supprimé sans"
                    + " votre confirmation, fichier par fichier.");
            ui.button(files, "Autoriser l'accès aux fichiers", Ui.PRIMARY, v -> a.requestFileAccess());
        } else {
            showFiles(a, files);
        }

        // 4. Cache
        c = ui.card(root);
        ui.title(c, "Cache des applis");
        ui.body(c, "Le cache sert à accélérer : images déjà téléchargées, miniatures… Le vider libère de la"
                + " place, mais les applis le reconstruisent et sont plus lentes au début. Utile seulement si le"
                + " stockage est presque plein.");
        if (Build.VERSION.SDK_INT >= 30 && FileScanner.hasAccess(a)) {
            ui.button(c, "Vider le cache de toutes les applis", Ui.SECONDARY, v -> a.clearAllCaches());
            if (freePct >= 20) ui.small(c, "Votre stockage n'est pas saturé : inutile pour l'instant.");
        } else if (Build.VERSION.SDK_INT >= 30) {
            ui.small(c, "Pour vider le cache de toutes les applis d'un coup, autorisez d'abord l'accès aux"
                    + " fichiers (ci-dessus), ou utilisez le script PC (option 11).");
        } else {
            ui.small(c, "Par appli : Paramètres › Applications › [appli] › Stockage › Vider le cache."
                    + " Toutes d'un coup : script PC (option 11).");
        }
    }

    // ------------------------------------------------------------------ applis

    private static void showApps(MainActivity a, LinearLayout card, List<AppScanner.AppItem> all) {
        Ui ui = a.ui;
        while (card.getChildCount() > 1) card.removeViewAt(1);
        if (all == null) {
            ui.status(card, Ui.URGENT, "Impossible de lire la liste des applis.");
            return;
        }
        long now = System.currentTimeMillis();
        List<AppScanner.AppItem> unused = new ArrayList<>();
        long unusedBytes = 0;
        for (AppScanner.AppItem it : all) {
            if (it.unused(now)) {
                unused.add(it);
                if (it.sizeBytes > 0) unusedBytes += it.sizeBytes;
            }
        }
        unused.sort((x, y) -> Long.compare(y.sizeBytes, x.sizeBytes));
        List<AppScanner.AppItem> bySize = new ArrayList<>(all);
        bySize.sort((x, y) -> Long.compare(y.sizeBytes, x.sizeBytes));

        if (unused.isEmpty()) {
            ui.status(card, Ui.GOOD, "Toutes vos applis ont servi ces 30 derniers jours.");
        } else {
            ui.status(card, Ui.FIX, unused.size() + (unused.size() > 1 ? " applis pas ouvertes" : " appli pas ouverte")
                    + " depuis 30 jours ou plus" + (unusedBytes > 0 ? " · " + Fmt.bytes(unusedBytes) : ""));
        }
        ui.small(card, "Une appli peut servir sans être ouverte (montre connectée, widget, sauvegarde) :"
                + " vérifiez avant de désinstaller. La désinstallation passe toujours par la fenêtre du système.");
        ui.segmented(card, new String[]{"Inutilisées (" + unused.size() + ")", "Toutes, par taille"},
                a.appsView, true, i -> {
                    a.appsView = i;
                    showApps(a, card, all);
                });
        List<AppScanner.AppItem> shown = a.appsView == 0 ? unused : bySize;
        for (int i = 0; i < shown.size() && i < MAX_ROWS; i++) appRow(a, card, shown.get(i), now);
        if (shown.size() > MAX_ROWS) ui.small(card, "… et " + (shown.size() - MAX_ROWS) + " autres.");
    }

    private static void appRow(MainActivity a, LinearLayout card, AppScanner.AppItem it, long now) {
        Ui ui = a.ui;
        ui.divider(card);
        LinearLayout r = ui.row(card);
        ImageView icon = new ImageView(a);
        icon.setImageDrawable(it.icon);
        icon.setImportantForAccessibility(ImageView.IMPORTANT_FOR_ACCESSIBILITY_NO);
        LinearLayout.LayoutParams ilp = new LinearLayout.LayoutParams(ui.dp(40), ui.dp(40));
        ilp.setMarginEnd(ui.dp(12));
        r.addView(icon, ilp);
        LinearLayout col = new LinearLayout(a);
        col.setOrientation(LinearLayout.VERTICAL);
        r.addView(col, new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1));
        TextView name = ui.text(col, it.label, 15, ui.text, ui.medium);
        name.setSingleLine(true);
        name.setEllipsize(TextUtils.TruncateAt.END);
        String when = it.lastUsed > 0
                ? "Ouverte " + Fmt.daysAgo((now - it.lastUsed) / Fmt.DAY)
                : "Aucune ouverture enregistrée (installée " + Fmt.daysAgo((now - it.installed) / Fmt.DAY) + ")";
        TextView d = ui.text(col, when + (it.sizeBytes > 0 ? " · " + Fmt.bytes(it.sizeBytes) : ""), 13.5f, ui.text2, null);
        ((LinearLayout.LayoutParams) d.getLayoutParams()).topMargin = ui.dp(2);
        LinearLayout actions = ui.buttonRow(card);
        actions.setPadding(ui.dp(52), ui.dp(8), 0, 0);
        ui.smallButton(actions, "Désinstaller", Ui.DANGER, v -> a.uninstall(it.pkg));
        ui.smallButton(actions, "Fiche de l'appli", Ui.QUIET, v -> a.openAppInfo(it.pkg));
    }

    // ------------------------------------------------------------------ fichiers

    private static void showFiles(MainActivity a, LinearLayout card) {
        Ui ui = a.ui;
        while (card.getChildCount() > 1) card.removeViewAt(1);
        FileScanner.Result r = a.lastFiles;
        if (r == null) {
            ui.body(card, "Recherche des fichiers de plus de 100 Mo et des APK dans tout le stockage partagé"
                    + " (les données privées des applis ne sont pas concernées).");
            ui.button(card, "Lancer la recherche", Ui.PRIMARY, v -> scanFiles(a, card));
            return;
        }
        r.items.removeIf(it -> !it.file.exists());
        long total = 0;
        for (FileScanner.Item it : r.items) total += it.size;
        if (r.items.isEmpty()) {
            ui.status(card, Ui.GOOD, "Aucun gros fichier ni APK oublié.");
        } else {
            ui.status(card, Ui.INFO, r.items.size() + (r.items.size() > 1 ? " fichiers · " : " fichier · ") + Fmt.bytes(total));
            ui.small(card, "Un APK ne sert plus une fois l'appli installée. Pour les vidéos et archives,"
                    + " vérifiez qu'elles sont sauvegardées ailleurs avant de les supprimer.");
        }
        if (r.truncated) ui.small(card, "Recherche arrêtée avant la fin (stockage très volumineux) : résultats partiels.");
        for (FileScanner.Item it : r.items) fileRow(a, card, it);
        ui.button(card, "Relancer la recherche", Ui.QUIET, v -> scanFiles(a, card));
    }

    private static void fileRow(MainActivity a, LinearLayout card, FileScanner.Item it) {
        Ui ui = a.ui;
        ui.divider(card);
        LinearLayout head = ui.row(card);
        TextView kind = ui.pill(null, it.kind(), it.apk ? Ui.FIX : Ui.INFO);
        LinearLayout.LayoutParams klp = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.WRAP_CONTENT,
                LinearLayout.LayoutParams.WRAP_CONTENT);
        klp.setMarginEnd(ui.dp(10));
        head.addView(kind, klp);
        TextView name = ui.text(null, it.file.getName(), 15, ui.text, ui.medium);
        name.setSingleLine(true);
        name.setEllipsize(TextUtils.TruncateAt.MIDDLE);
        head.addView(name, new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1));
        ui.small(card, Fmt.bytes(it.size) + " · " + (it.folder.isEmpty() ? "racine du stockage" : it.folder));
        LinearLayout actions = ui.buttonRow(card);
        ui.smallButton(actions, "Supprimer", Ui.DANGER, v -> confirmDelete(a, card, it));
    }

    private static void confirmDelete(MainActivity a, LinearLayout card, FileScanner.Item it) {
        new AlertDialog.Builder(a)
                .setTitle("Supprimer ce fichier ?")
                .setMessage(it.file.getName() + "\n" + Fmt.bytes(it.size) + " · "
                        + (it.folder.isEmpty() ? "racine du stockage" : it.folder)
                        + "\n\nLa suppression est définitive.")
                .setNegativeButton("Annuler", null)
                .setPositiveButton("Supprimer", (dlg, w) -> {
                    if (it.file.delete()) {
                        MediaScannerConnection.scanFile(a, new String[]{it.file.getAbsolutePath()}, null, null);
                        a.toast(Fmt.bytes(it.size) + " libérés");
                    } else {
                        a.toast("Suppression impossible (fichier protégé ou déjà supprimé)");
                    }
                    showFiles(a, card);
                })
                .show();
    }

    private static void scanFiles(MainActivity a, LinearLayout card) {
        while (card.getChildCount() > 1) card.removeViewAt(1);
        a.ui.small(card, "Recherche en cours… (30 secondes au plus)");
        a.runBg(() -> {
            FileScanner.Result res = FileScanner.scan(FileScanner.root(), 30_000);
            a.lastFiles = res; // conservé même si l'onglet a changé entre-temps
            return res;
        }, res -> {
            if (res == null) {
                while (card.getChildCount() > 1) card.removeViewAt(1);
                a.ui.status(card, Ui.URGENT, "La recherche a échoué.");
                a.ui.button(card, "Réessayer", Ui.SECONDARY, v -> scanFiles(a, card));
            } else {
                showFiles(a, card);
            }
        });
    }
}
