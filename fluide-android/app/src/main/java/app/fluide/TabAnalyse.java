package app.fluide;

import android.content.Context;
import android.content.SharedPreferences;
import android.widget.LinearLayout;

import java.util.List;

/** Onglet « Analyse » : ce qui freine ce téléphone, classé par priorité. */
final class TabAnalyse {
    static final String INTRO_READ = "intro_lue";

    private TabAnalyse() {}

    static void build(MainActivity a, LinearLayout root) {
        Ui ui = a.ui;

        SharedPreferences prefs = a.getSharedPreferences(ResultStore.PREFS, Context.MODE_PRIVATE);
        if (!prefs.getBoolean(INTRO_READ, false)) {
            LinearLayout intro = ui.card(root);
            ui.title(intro, "Ce que Fluide fait, et ne fait pas");
            ui.body(intro, "Aucune appli ne transforme un téléphone en iPhone : la fluidité dépend d'abord du"
                    + " processeur, de la mémoire, du stockage et de l'écran. Fluide repère ce qui freine le vôtre,"
                    + " corrige ce qui peut l'être et mesure le résultat. Pas de « booster » ni de nettoyage de RAM :"
                    + " ils sont contre-productifs (voir l'onglet Vrai/Faux).");
            ui.button(intro, "J'ai compris", Ui.QUIET, v -> {
                prefs.edit().putBoolean(INTRO_READ, true).apply();
                a.refresh();
            });
        }

        LinearLayout loading = ui.card(root);
        ui.small(loading, "Analyse en cours…");

        a.runBg(() -> Diagnostics.collect(a), snap -> {
            root.removeView(loading);
            if (snap == null) {
                LinearLayout err = ui.card(root);
                ui.status(err, Ui.URGENT, "L'analyse a échoué. Relancez l'appli.");
                return;
            }
            device(a, root, snap);
            findings(a, root, Diagnostics.findings(snap));
        });
    }

    private static void device(MainActivity a, LinearLayout root, Diagnostics.Snapshot s) {
        Ui ui = a.ui;
        LinearLayout c = ui.card(root);
        ui.title(c, s.manufacturer + " " + s.model);
        ui.kv(c, "Android", s.release + (s.patch.isEmpty() ? "" : " · correctif du " + s.patch));
        if (!s.soc.isEmpty()) ui.kv(c, "Processeur", s.soc);
        ui.kv(c, "Mémoire vive", s.ramNominalGb > 0 ? s.ramNominalGb + " Go" : "inconnue");
        if (s.storageTotal > 0) {
            ui.kv(c, "Stockage", Fmt.bytes(s.storageFree) + " libres sur " + Fmt.bytes(s.storageTotal));
        }
        ui.kv(c, "Écran", "jusqu'à " + Fmt.hz(s.refreshMax));
        if (s.perfClass > 0) {
            ui.kv(c, "Classe de perf.", "Android " + androidName(s.perfClass) + " (exigences Google respectées)");
        }
        ui.kv(c, "Allumé depuis", Fmt.duration(s.uptimeMs));
    }

    private static void findings(MainActivity a, LinearLayout root, List<Diagnostics.Finding> list) {
        Ui ui = a.ui;
        int urgent = 0, fix = 0, limit = 0;
        for (Diagnostics.Finding f : list) {
            if (f.level == Ui.URGENT) urgent++;
            else if (f.level == Ui.FIX) fix++;
            else if (f.level == Ui.LIMIT) limit++;
        }

        LinearLayout sum = ui.card(root);
        ui.title(sum, "Bilan");
        if (urgent + fix == 0) {
            ui.status(sum, Ui.GOOD, "Rien d'important à corriger côté réglages.");
        } else {
            ui.status(sum, urgent > 0 ? Ui.URGENT : Ui.FIX,
                    (urgent + fix) + (urgent + fix > 1 ? " points à corriger" : " point à corriger"));
        }
        if (limit == 1) {
            ui.small(sum, "1 limite matérielle : aucune appli ne peut la corriger, mais elle explique une"
                    + " partie du ressenti.");
        } else if (limit > 1) {
            ui.small(sum, limit + " limites matérielles : aucune appli ne peut les corriger, mais elles"
                    + " expliquent une partie du ressenti.");
        }
        ui.small(sum, "Ce bilan lit des réglages ; il ne mesure pas la fluidité. Pour un chiffre réel,"
                + " lancez le test de 10 secondes, avant et après vos changements.");
        ui.button(sum, "Mesurer la fluidité (10 s)", Ui.PRIMARY, v -> a.startTest());

        for (Diagnostics.Finding f : list) {
            Runnable act = f.action == Diagnostics.ACT_NONE ? null : () -> a.handleAction(f.action);
            ui.finding(root, f.level, f.title, f.detail, f.actionLabel, act);
        }
    }

    static String androidName(int sdk) {
        switch (sdk) {
            case 30: return "11";
            case 31: case 32: return "12";
            case 33: return "13";
            case 34: return "14";
            case 35: return "15";
            case 36: return "16";
            default: return sdk > 36 ? String.valueOf(sdk - 20) : String.valueOf(sdk);
        }
    }
}
