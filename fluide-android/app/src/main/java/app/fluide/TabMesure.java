package app.fluide;

import android.app.AlertDialog;
import android.widget.LinearLayout;

import java.util.ArrayList;
import java.util.List;

/** Onglet « Mesure » : le seul juge de paix, avant et après chaque changement. */
final class TabMesure {
    static final double SIGNIFICANT = 2.0; // points d'écart sous lesquels deux essais sont équivalents

    private TabMesure() {}

    static void build(MainActivity a, LinearLayout root) {
        Ui ui = a.ui;
        List<ResultStore.Entry> hist = ResultStore.load(a);

        LinearLayout c = ui.card(root);
        ui.title(c, "Mesurer la fluidité");
        ui.body(c, "Un fil d'actualité type (cartes, ombres, images) défile tout seul pendant 10 secondes."
                + " Le système chronomètre chaque image ; Fluide compte celles qui arrivent en retard. Ce sont"
                + " elles que l'œil perçoit comme des saccades.");
        ui.button(c, hist.isEmpty() ? "Lancer le test (10 s)" : "Relancer le test (10 s)", Ui.PRIMARY, v -> a.startTest());
        ui.small(c, "Pour comparer avant / après un réglage : batterie au-dessus de 20 %, téléphone froid,"
                + " économie d'énergie coupée, et 2 ou 3 essais à chaque fois.");

        if (!hist.isEmpty()) last(a, root, hist);
        if (hist.size() > 1) history(a, root, hist);
        howToRead(a, root);
    }

    private static void last(MainActivity a, LinearLayout root, List<ResultStore.Entry> hist) {
        Ui ui = a.ui;
        ResultStore.Entry e = hist.get(0);
        LinearLayout c = ui.card(root);
        ui.title(c, "Dernier résultat");
        ui.pill(c, FrameStats.verdict(e.latePct), FrameStats.level(e.latePct));
        ui.gap(c, 8);
        ui.kv(c, "Images en retard", Fmt.pct(e.latePct) + (e.deadlineBased ? "" : " (estimation)"));
        ui.kv(c, "Images par seconde", Math.round(e.fps) + " pour un écran à " + Fmt.hz(e.hz));
        ui.kv(c, "95 % des images", "en moins de " + Fmt.ms(e.p95) + " (budget : " + Fmt.ms(e.budgetMs) + ")");
        ui.kv(c, "Images analysées", String.valueOf(e.frames));
        ui.small(c, Fmt.dateTime(e.time) + " · " + conditions(e));
        if (e.powerSave) ui.small(c, "Mesure faite en économie d'énergie : résultat bridé, à refaire mode coupé.");
        if (e.thermal >= 2) ui.small(c, "Mesure faite téléphone chaud : le processeur était bridé.");
        if (e.hz > 0 && e.fps < e.hz * 0.9) {
            ui.small(c, "Le téléphone n'a pas suivi la cadence de son écran : des images ont été sautées.");
        }

        if (hist.size() > 1) {
            ResultStore.Entry p = hist.get(1);
            double diff = e.latePct - p.latePct;
            ui.divider(c);
            if (Math.abs(diff) < SIGNIFICANT) {
                ui.status(c, Ui.INFO, "Pas de différence nette avec la mesure précédente");
                ui.small(c, "Écart de " + Fmt.num1(Math.abs(diff)) + " point : c'est dans la variation normale"
                        + " d'un essai à l'autre.");
            } else if (diff < 0) {
                ui.status(c, Ui.GOOD, "Mieux que la mesure précédente : " + Fmt.num1(-diff) + " points de moins");
            } else {
                ui.status(c, Ui.FIX, "Moins bien que la mesure précédente : " + Fmt.num1(diff) + " points de plus");
            }
            List<String> changed = changes(p, e);
            if (!changed.isEmpty()) {
                ui.small(c, "Conditions différentes entre les deux mesures (" + String.join(", ", changed)
                        + ") : la comparaison n'est pas équitable.");
            }
        }
    }

    static String conditions(ResultStore.Entry e) {
        StringBuilder b = new StringBuilder("animations ").append(Fmt.scale(e.anim));
        b.append(" · économie d'énergie ").append(e.powerSave ? "activée" : "coupée");
        if (!Float.isNaN(e.temp)) b.append(" · batterie ").append(Fmt.num1(e.temp)).append(Fmt.NBSP).append("°C");
        return b.toString();
    }

    static List<String> changes(ResultStore.Entry before, ResultStore.Entry after) {
        List<String> out = new ArrayList<>();
        if (before.powerSave != after.powerSave) out.add("économie d'énergie");
        if (Math.abs(before.hz - after.hz) > 1) out.add("fréquence d'écran");
        if (!Float.isNaN(before.temp) && !Float.isNaN(after.temp) && Math.abs(before.temp - after.temp) >= 5) {
            out.add("température");
        }
        return out;
    }

    private static void history(MainActivity a, LinearLayout root, List<ResultStore.Entry> hist) {
        Ui ui = a.ui;
        LinearLayout c = ui.card(root);
        ui.title(c, "Historique");
        for (int i = 0; i < hist.size() && i < 10; i++) {
            ResultStore.Entry e = hist.get(i);
            if (i > 0) ui.divider(c);
            ui.status(c, FrameStats.level(e.latePct), Fmt.pct(e.latePct) + " en retard · "
                    + Math.round(e.fps) + " images/s");
            ui.small(c, Fmt.dateTime(e.time) + " · " + conditions(e));
        }
        ui.button(c, "Effacer l'historique", Ui.QUIET, v -> new AlertDialog.Builder(a)
                .setTitle("Effacer l'historique ?")
                .setMessage("Toutes les mesures enregistrées seront supprimées.")
                .setNegativeButton("Annuler", null)
                .setPositiveButton("Effacer", (d, w) -> {
                    ResultStore.clear(a);
                    a.refresh();
                })
                .show());
    }

    private static void howToRead(MainActivity a, LinearLayout root) {
        Ui ui = a.ui;
        LinearLayout c = ui.card(root);
        ui.title(c, "Comment lire le résultat");
        ui.bullets(c,
                "Images en retard : part des images qui n'étaient pas prêtes à temps. Sous 3 %, le défilement"
                        + " paraît régulier ; au-delà de 7 %, les à-coups se voient.",
                "Images par seconde : ce que le téléphone a réellement produit, à comparer à la fréquence de"
                        + " l'écran.",
                "95 % des images : temps de calcul sous lequel tombent 95 % des images. Il doit rester sous le"
                        + " budget (8,3 ms à 120 Hz, 16,7 ms à 60 Hz).",
                "C'est un défilement type, pas une note absolue : un jeu ou une appli lourde peut faire moins"
                        + " bien. Servez-vous-en pour comparer votre téléphone à lui-même.");
    }
}
