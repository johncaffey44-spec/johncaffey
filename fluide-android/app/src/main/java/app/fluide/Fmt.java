package app.fluide;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

/** Mise en forme à la française : virgule décimale, espaces insécables, unités du système. */
final class Fmt {
    static final Locale FR = Locale.FRANCE;
    static final String NBSP = " ";
    static final long DAY = 24L * 60 * 60 * 1000;

    private Fmt() {}

    /** Une décimale au plus, sans « ,0 » inutile. */
    static String num1(double v) {
        String s = String.format(FR, "%.1f", v);
        if (s.endsWith(",0")) s = s.substring(0, s.length() - 2);
        return s;
    }

    /** Tailles en base 1000, comme les Paramètres d'Android depuis la version 8. */
    static String bytes(long b) {
        if (b < 0) return "?";
        if (b < 1000) return b + NBSP + "o";
        String[] units = {"Ko", "Mo", "Go", "To"};
        double v = b / 1000.0;
        int i = 0;
        while (v >= 1000 && i < units.length - 1) {
            v /= 1000;
            i++;
        }
        String n = v >= 100 ? String.format(FR, "%.0f", v) : num1(v);
        return n + NBSP + units[i];
    }

    static String pct(double v) {
        return num1(v) + NBSP + "%";
    }

    static String hz(double hz) {
        return Math.round(hz) + NBSP + "Hz";
    }

    static String ms(double ms) {
        return num1(ms) + NBSP + "ms";
    }

    /** Facteur d'animation : « 1x », « 0,5x », « désactivées ». */
    static String scale(float s) {
        if (s <= 0f) return "désactivées";
        return num1(s) + "x";
    }

    static String duration(long ms) {
        long days = ms / DAY;
        if (days >= 1) return days + NBSP + (days > 1 ? "jours" : "jour");
        long hours = ms / (60L * 60 * 1000);
        if (hours >= 1) return hours + NBSP + "h";
        return Math.max(1, ms / 60000) + NBSP + "min";
    }

    static String daysAgo(long days) {
        if (days <= 0) return "aujourd'hui";
        if (days == 1) return "hier";
        if (days < 60) return "il y a " + days + NBSP + "j";
        if (days < 730) return "il y a " + (days / 30) + NBSP + "mois";
        return "il y a " + (days / 365) + NBSP + "ans";
    }

    static String dateTime(long ts) {
        return new SimpleDateFormat("d MMM yyyy 'à' HH:mm", FR).format(new Date(ts));
    }
}
