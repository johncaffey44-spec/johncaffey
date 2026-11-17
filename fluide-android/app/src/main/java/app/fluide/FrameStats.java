package app.fluide;

import java.util.Arrays;

/**
 * Statistiques d'images d'un test de défilement.
 *
 * Une image est « en retard » quand sa durée totale (FrameMetrics.TOTAL_DURATION) dépasse
 * son échéance : FrameMetrics.DEADLINE depuis Android 12, sinon la durée d'un rafraîchissement
 * (1 / fréquence). C'est la définition de « Janky frames » de dumpsys gfxinfo.
 *
 * Remplie par le fil des métriques, lue par le fil principal : méthodes synchronisées.
 */
final class FrameStats {
    private long[] total = new long[1024];
    private long[] deadline = new long[1024];
    private long[] vsync = new long[1024];
    private int n;
    private long lost;

    synchronized void add(long totalNs, long deadlineNs, long vsyncNs) {
        if (n == total.length) {
            int cap = n * 2;
            total = Arrays.copyOf(total, cap);
            deadline = Arrays.copyOf(deadline, cap);
            vsync = Arrays.copyOf(vsync, cap);
        }
        total[n] = totalNs;
        deadline[n] = deadlineNs;
        vsync[n] = vsyncNs;
        n++;
    }

    /** Rapports de métriques perdus (fil d'écoute en retard) : ce sont quand même des images rendues. */
    synchronized void addLost(int count) {
        lost += count;
    }

    synchronized int count() {
        return n;
    }

    static final class Summary {
        int frames;
        long lost;
        int late;
        double latePct;
        double fps;
        double budgetMs;
        double p50, p90, p95, p99;
        boolean deadlineBased;
    }

    synchronized Summary summarize(double refreshHz) {
        Summary s = new Summary();
        s.frames = n;
        s.lost = lost;
        if (n == 0) return s;
        double periodNs = 1e9 / (refreshHz > 1 ? refreshHz : 60.0);

        long[] budgets = new long[n];
        int late = 0;
        boolean anyDeadline = false;
        long vMin = Long.MAX_VALUE, vMax = Long.MIN_VALUE;
        for (int i = 0; i < n; i++) {
            long b = deadline[i] > 0 ? deadline[i] : Math.round(periodNs);
            anyDeadline |= deadline[i] > 0;
            budgets[i] = b;
            if (total[i] > b) late++;
            if (vsync[i] > 0) {
                vMin = Math.min(vMin, vsync[i]);
                vMax = Math.max(vMax, vsync[i]);
            }
        }
        s.late = late;
        s.latePct = 100.0 * late / n;
        s.deadlineBased = anyDeadline;
        Arrays.sort(budgets);
        s.budgetMs = budgets[n / 2] / 1e6;

        long[] sorted = Arrays.copyOf(total, n);
        Arrays.sort(sorted);
        s.p50 = percentile(sorted, 50) / 1e6;
        s.p90 = percentile(sorted, 90) / 1e6;
        s.p95 = percentile(sorted, 95) / 1e6;
        s.p99 = percentile(sorted, 99) / 1e6;

        if (vMax > vMin) {
            double seconds = (vMax - vMin) / 1e9;
            s.fps = (n + lost - 1) / seconds;
            // Arrondis d'horodatage : on ne dépasse pas la fréquence de l'écran pour quelques dixièmes.
            if (refreshHz > 1 && s.fps > refreshHz && s.fps < refreshHz * 1.05) s.fps = refreshHz;
        }
        return s;
    }

    /** Rang le plus proche, sur un tableau trié. */
    static long percentile(long[] sorted, double p) {
        if (sorted.length == 0) return 0;
        int rank = (int) Math.ceil(p / 100.0 * sorted.length);
        return sorted[Math.max(0, Math.min(sorted.length - 1, rank - 1))];
    }

    /** Niveau (couleur) et libellé, à partir du pourcentage d'images en retard. */
    static int level(double latePct) {
        if (latePct <= 3) return Ui.GOOD;
        if (latePct <= 7) return Ui.INFO;
        if (latePct <= 15) return Ui.FIX;
        return Ui.URGENT;
    }

    static String verdict(double latePct) {
        if (latePct <= 1) return "Excellent : défilement parfaitement régulier";
        if (latePct <= 3) return "Très bon : à-coups rarissimes";
        if (latePct <= 7) return "Correct : quelques à-coups";
        if (latePct <= 15) return "Saccadé : à-coups visibles";
        return "Très saccadé";
    }
}
