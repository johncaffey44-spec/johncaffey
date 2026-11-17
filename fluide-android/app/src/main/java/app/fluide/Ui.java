package app.fluide;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.Context;
import android.content.res.ColorStateList;
import android.content.res.Configuration;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Insets;
import android.graphics.Paint;
import android.graphics.RectF;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.graphics.drawable.RippleDrawable;
import android.os.Build;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.Window;
import android.view.WindowInsets;
import android.view.WindowInsetsController;
import android.widget.Button;
import android.widget.HorizontalScrollView;
import android.widget.LinearLayout;
import android.widget.TextView;

import java.util.function.IntConsumer;

/** Composants d'interface dessinés en code : aucune bibliothèque, rendu identique partout. */
final class Ui {
    // Niveaux d'un constat (ordre d'affichage : URGENT, FIX, LIMIT, INFO, GOOD).
    static final int GOOD = 0, INFO = 1, FIX = 2, URGENT = 3, LIMIT = 4;
    // Styles de bouton.
    static final int PRIMARY = 0, SECONDARY = 1, DANGER = 2, QUIET = 3;

    private static final int MATCH = ViewGroup.LayoutParams.MATCH_PARENT;
    private static final int WRAP = ViewGroup.LayoutParams.WRAP_CONTENT;

    final Context ctx;
    final float density;
    final int bg, surface, surface2, text, text2, divider, accent, onAccent, accentSoft, ripple;
    final int good, goodSoft, warn, warnSoft, bad, badSoft, limit, limitSoft, info, infoSoft;
    final Typeface medium;
    final Typeface bold;

    Ui(Context c) {
        ctx = c;
        density = c.getResources().getDisplayMetrics().density;
        bg = c.getColor(R.color.bg);
        surface = c.getColor(R.color.surface);
        surface2 = c.getColor(R.color.surface2);
        text = c.getColor(R.color.text);
        text2 = c.getColor(R.color.text2);
        divider = c.getColor(R.color.divider);
        accent = c.getColor(R.color.accent);
        onAccent = c.getColor(R.color.on_accent);
        accentSoft = c.getColor(R.color.accent_soft);
        ripple = c.getColor(R.color.ripple);
        good = c.getColor(R.color.good);
        goodSoft = c.getColor(R.color.good_soft);
        warn = c.getColor(R.color.warn);
        warnSoft = c.getColor(R.color.warn_soft);
        bad = c.getColor(R.color.bad);
        badSoft = c.getColor(R.color.bad_soft);
        limit = c.getColor(R.color.limit);
        limitSoft = c.getColor(R.color.limit_soft);
        info = c.getColor(R.color.info);
        infoSoft = c.getColor(R.color.info_soft);
        medium = Typeface.create("sans-serif-medium", Typeface.NORMAL);
        bold = Typeface.create(Typeface.DEFAULT, Typeface.BOLD);
    }

    int dp(float v) {
        return Math.round(v * density);
    }

    int levelColor(int level) {
        switch (level) {
            case GOOD: return good;
            case FIX: return warn;
            case URGENT: return bad;
            case LIMIT: return limit;
            default: return info;
        }
    }

    int levelSoft(int level) {
        switch (level) {
            case GOOD: return goodSoft;
            case FIX: return warnSoft;
            case URGENT: return badSoft;
            case LIMIT: return limitSoft;
            default: return infoSoft;
        }
    }

    static String levelLabel(int level) {
        switch (level) {
            case GOOD: return "OK";
            case FIX: return "À corriger";
            case URGENT: return "Urgent";
            case LIMIT: return "Limite matérielle";
            default: return "Conseil";
        }
    }

    GradientDrawable rounded(int color, float radiusDp) {
        GradientDrawable d = new GradientDrawable();
        d.setColor(color);
        d.setCornerRadius(dp(radiusDp));
        return d;
    }

    // ---- Conteneurs ----

    LinearLayout card(ViewGroup parent) {
        LinearLayout c = new LinearLayout(ctx);
        c.setOrientation(LinearLayout.VERTICAL);
        c.setBackground(rounded(surface, 18));
        c.setPadding(dp(16), dp(16), dp(16), dp(16));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(MATCH, WRAP);
        lp.bottomMargin = dp(12);
        parent.addView(c, lp);
        return c;
    }

    LinearLayout column(ViewGroup parent) {
        LinearLayout c = new LinearLayout(ctx);
        c.setOrientation(LinearLayout.VERTICAL);
        if (parent != null) parent.addView(c, new LinearLayout.LayoutParams(MATCH, WRAP));
        return c;
    }

    LinearLayout row(ViewGroup parent) {
        LinearLayout r = new LinearLayout(ctx);
        r.setOrientation(LinearLayout.HORIZONTAL);
        r.setGravity(Gravity.CENTER_VERTICAL);
        if (parent != null) parent.addView(r, new LinearLayout.LayoutParams(MATCH, WRAP));
        return r;
    }

    void gap(ViewGroup parent, float heightDp) {
        View v = new View(ctx);
        parent.addView(v, new LinearLayout.LayoutParams(1, dp(heightDp)));
    }

    void divider(ViewGroup parent) {
        View v = new View(ctx);
        v.setBackgroundColor(divider);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(MATCH, Math.max(1, dp(1) / 2 + 1));
        lp.topMargin = dp(10);
        lp.bottomMargin = dp(10);
        parent.addView(v, lp);
    }

    // ---- Textes ----

    TextView text(ViewGroup parent, CharSequence s, float sp, int color, Typeface tf) {
        TextView t = new TextView(ctx);
        t.setText(s);
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, sp);
        t.setTextColor(color);
        if (tf != null) t.setTypeface(tf);
        t.setLineSpacing(0, 1.15f);
        if (parent != null) parent.addView(t, new LinearLayout.LayoutParams(MATCH, WRAP));
        return t;
    }

    TextView title(ViewGroup parent, CharSequence s) {
        TextView t = text(parent, s, 18, text, medium);
        ((LinearLayout.LayoutParams) t.getLayoutParams()).bottomMargin = dp(6);
        return t;
    }

    TextView body(ViewGroup parent, CharSequence s) {
        return text(parent, s, 15, text, null);
    }

    TextView small(ViewGroup parent, CharSequence s) {
        TextView t = text(parent, s, 13.5f, text2, null);
        ((LinearLayout.LayoutParams) t.getLayoutParams()).topMargin = dp(6);
        return t;
    }

    /** Liste à puces : une ligne par élément. */
    void bullets(ViewGroup parent, String... items) {
        for (String s : items) {
            LinearLayout r = new LinearLayout(ctx);
            r.setOrientation(LinearLayout.HORIZONTAL);
            TextView dot = text(null, "•", 15, accent, bold);
            dot.setPadding(0, 0, dp(10), 0);
            r.addView(dot, new LinearLayout.LayoutParams(WRAP, WRAP));
            TextView t = text(null, s, 14.5f, text, null);
            r.addView(t, new LinearLayout.LayoutParams(0, WRAP, 1));
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(MATCH, WRAP);
            lp.topMargin = dp(6);
            parent.addView(r, lp);
        }
    }

    /**
     * Bloc de commande sélectionnable, en police à chasse fixe. Une commande par ligne, sans
     * retour à la ligne au milieu d'un mot : le bloc défile horizontalement si besoin.
     */
    TextView code(ViewGroup parent, String s) {
        TextView t = text(null, s, 13, text, Typeface.MONOSPACE);
        t.setTextIsSelectable(true);
        HorizontalScrollView box = new HorizontalScrollView(ctx);
        box.setBackground(rounded(surface2, 10));
        box.setPadding(dp(12), dp(10), dp(12), dp(10));
        box.setClipToPadding(false);
        box.setFillViewport(true);
        box.setScrollBarStyle(View.SCROLLBARS_OUTSIDE_OVERLAY); // au bord du bloc, pas sur le texte
        box.addView(t, new HorizontalScrollView.LayoutParams(WRAP, WRAP));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(MATCH, WRAP);
        lp.topMargin = dp(8);
        parent.addView(box, lp);
        return t;
    }

    /** Ligne « libellé : valeur ». */
    void kv(ViewGroup parent, String key, CharSequence value) {
        LinearLayout r = new LinearLayout(ctx);
        r.setOrientation(LinearLayout.HORIZONTAL);
        r.setPadding(0, dp(5), 0, dp(5));
        TextView k = text(null, key, 14, text2, null);
        r.addView(k, new LinearLayout.LayoutParams(0, WRAP, 0.40f));
        TextView v = text(null, value, 14, text, medium);
        r.addView(v, new LinearLayout.LayoutParams(0, WRAP, 0.60f));
        parent.addView(r, new LinearLayout.LayoutParams(MATCH, WRAP));
    }

    TextView pill(ViewGroup parent, String label, int level) {
        TextView t = text(null, label, 12, levelColor(level), medium);
        t.setBackground(rounded(levelSoft(level), 10));
        t.setPadding(dp(9), dp(3), dp(9), dp(3));
        t.setSingleLine(true);
        if (parent != null) parent.addView(t, new LinearLayout.LayoutParams(WRAP, WRAP));
        return t;
    }

    // ---- Boutons ----

    Button button(ViewGroup parent, String label, int style, View.OnClickListener l) {
        Button b = new Button(ctx);
        b.setText(label);
        b.setAllCaps(false);
        b.setStateListAnimator(null);
        b.setGravity(Gravity.CENTER);
        b.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15);
        b.setTypeface(medium);
        b.setMinHeight(dp(46));
        b.setMinimumHeight(dp(46));
        b.setMinWidth(0);
        b.setMinimumWidth(0);
        b.setPadding(dp(16), dp(10), dp(16), dp(10));
        int bgc;
        int fg;
        switch (style) {
            case PRIMARY: bgc = accent; fg = onAccent; break;
            case DANGER: bgc = badSoft; fg = bad; break;
            case QUIET: bgc = Color.TRANSPARENT; fg = accent; break;
            default: bgc = accentSoft; fg = accent; break;
        }
        b.setTextColor(fg);
        b.setBackground(new RippleDrawable(ColorStateList.valueOf(ripple), rounded(bgc, 14), rounded(Color.WHITE, 14)));
        b.setOnClickListener(l);
        if (parent != null) {
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(MATCH, WRAP);
            lp.topMargin = dp(10);
            parent.addView(b, lp);
        }
        return b;
    }

    /**
     * Rangée de petits boutons. L'écart vient du rembourrage de la rangée, pas d'une marge des
     * boutons : centrés verticalement, LinearLayout compterait la marge deux fois et rognerait le bas.
     */
    LinearLayout buttonRow(ViewGroup parent) {
        LinearLayout r = row(parent);
        r.setPadding(0, dp(8), 0, 0);
        return r;
    }

    /** Petit bouton compact, à placer dans une buttonRow(). */
    Button smallButton(ViewGroup parent, String label, int style, View.OnClickListener l) {
        Button b = button(null, label, style, l);
        b.setTextSize(TypedValue.COMPLEX_UNIT_SP, 13.5f);
        b.setMinHeight(dp(38));
        b.setMinimumHeight(dp(38));
        b.setPadding(dp(12), dp(6), dp(12), dp(6));
        if (parent != null) {
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(WRAP, WRAP);
            lp.setMarginEnd(dp(8));
            parent.addView(b, lp);
        }
        return b;
    }

    void enable(View v, boolean on) {
        v.setEnabled(on);
        v.setAlpha(on ? 1f : 0.45f);
    }

    /** Sélecteur à segments ; selected = -1 si aucune valeur ne correspond. */
    LinearLayout segmented(ViewGroup parent, String[] labels, int selected, boolean enabled, IntConsumer onPick) {
        LinearLayout r = new LinearLayout(ctx);
        r.setOrientation(LinearLayout.HORIZONTAL);
        for (int i = 0; i < labels.length; i++) {
            final int idx = i;
            boolean sel = i == selected;
            TextView t = text(null, labels[i], 14, sel ? onAccent : text, sel ? medium : null);
            t.setGravity(Gravity.CENTER);
            t.setPadding(dp(6), dp(10), dp(6), dp(10));
            t.setBackground(new RippleDrawable(ColorStateList.valueOf(ripple),
                    rounded(sel ? accent : surface2, 12), rounded(Color.WHITE, 12)));
            t.setClickable(true);
            t.setFocusable(true);
            t.setSelected(sel);
            t.setContentDescription(labels[i].replace('\n', ' ') + (sel ? ", sélectionné" : ""));
            t.setOnClickListener(v -> onPick.accept(idx));
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(0, WRAP, 1);
            if (i > 0) lp.setMarginStart(dp(6));
            r.addView(t, lp);
            enable(t, enabled);
        }
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(MATCH, WRAP);
        lp.topMargin = dp(10);
        parent.addView(r, lp);
        return r;
    }

    // ---- Constats ----

    /** Carte d'un constat : liseré coloré, titre, pastille de niveau, détail et action facultative. */
    void finding(ViewGroup parent, int level, String title, CharSequence detail, String action, Runnable onAction) {
        LinearLayout outer = new LinearLayout(ctx);
        outer.setOrientation(LinearLayout.HORIZONTAL);
        outer.setBackground(rounded(surface, 16));
        outer.setPadding(0, 0, dp(14), 0);
        View stripe = new View(ctx);
        stripe.setBackground(rounded(levelColor(level), 3));
        LinearLayout.LayoutParams slp = new LinearLayout.LayoutParams(dp(5), MATCH);
        slp.setMargins(dp(8), dp(14), dp(12), dp(14));
        outer.addView(stripe, slp);

        LinearLayout col = new LinearLayout(ctx);
        col.setOrientation(LinearLayout.VERTICAL);
        col.setPadding(0, dp(13), 0, dp(13));
        outer.addView(col, new LinearLayout.LayoutParams(0, WRAP, 1));

        LinearLayout head = row(col);
        TextView t = text(null, title, 15.5f, text, medium);
        head.addView(t, new LinearLayout.LayoutParams(0, WRAP, 1));
        TextView p = pill(null, levelLabel(level), level);
        LinearLayout.LayoutParams plp = new LinearLayout.LayoutParams(WRAP, WRAP);
        plp.setMarginStart(dp(8));
        head.addView(p, plp);

        if (detail != null && detail.length() > 0) {
            TextView d = text(col, detail, 14, text2, null);
            ((LinearLayout.LayoutParams) d.getLayoutParams()).topMargin = dp(4);
        }
        if (action != null && onAction != null) {
            Button b = button(null, action, SECONDARY, v -> onAction.run());
            b.setTextSize(TypedValue.COMPLEX_UNIT_SP, 14);
            b.setMinHeight(dp(40));
            b.setMinimumHeight(dp(40));
            LinearLayout.LayoutParams blp = new LinearLayout.LayoutParams(WRAP, WRAP);
            blp.topMargin = dp(10);
            col.addView(b, blp);
        }
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(MATCH, WRAP);
        lp.bottomMargin = dp(10);
        parent.addView(outer, lp);
    }

    /** Ligne de statut simple : pastille de couleur et texte. */
    void status(ViewGroup parent, int level, CharSequence s) {
        LinearLayout r = row(parent);
        View dot = new View(ctx);
        GradientDrawable g = new GradientDrawable();
        g.setShape(GradientDrawable.OVAL);
        g.setColor(levelColor(level));
        dot.setBackground(g);
        LinearLayout.LayoutParams dlp = new LinearLayout.LayoutParams(dp(10), dp(10));
        dlp.setMarginEnd(dp(10));
        r.addView(dot, dlp);
        TextView t = text(null, s, 15, text, medium);
        r.addView(t, new LinearLayout.LayoutParams(0, WRAP, 1));
    }

    // ---- Fenêtre bord à bord ----

    /** Contenu sous les barres système (imposé par Android 15+), avec icônes lisibles en clair comme en sombre. */
    @SuppressWarnings("deprecation")
    static void edgeToEdge(Activity a) {
        Window w = a.getWindow();
        // Crée la vue racine maintenant : avant setContentView, Window.getInsetsController()
        // plante (NullPointerException) sur Android 11 et plus.
        View decor = w.getDecorView();
        boolean night = (a.getResources().getConfiguration().uiMode & Configuration.UI_MODE_NIGHT_MASK)
                == Configuration.UI_MODE_NIGHT_YES;
        w.setStatusBarColor(Color.TRANSPARENT);
        w.setNavigationBarColor(Color.TRANSPARENT);
        if (Build.VERSION.SDK_INT >= 29) w.setNavigationBarContrastEnforced(false);
        if (Build.VERSION.SDK_INT >= 30) {
            w.setDecorFitsSystemWindows(false);
            WindowInsetsController c = decor.getWindowInsetsController();
            if (c != null) {
                int mask = WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS
                        | WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS;
                c.setSystemBarsAppearance(night ? 0 : mask, mask);
            }
        } else {
            int flags = View.SYSTEM_UI_FLAG_LAYOUT_STABLE | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                    | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION;
            if (!night) flags |= View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR | View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR;
            decor.setSystemUiVisibility(flags);
        }
    }

    /** Marges des barres système et de l'encoche : {gauche, haut, droite, bas}. */
    @SuppressWarnings("deprecation")
    static int[] systemInsets(WindowInsets in) {
        if (Build.VERSION.SDK_INT >= 30) {
            Insets i = in.getInsets(WindowInsets.Type.systemBars() | WindowInsets.Type.displayCutout());
            return new int[]{i.left, i.top, i.right, i.bottom};
        }
        return new int[]{in.getSystemWindowInsetLeft(), in.getSystemWindowInsetTop(),
                in.getSystemWindowInsetRight(), in.getSystemWindowInsetBottom()};
    }

    BarView bar(ViewGroup parent, float fraction, int color) {
        BarView b = new BarView(ctx, surface2, color, fraction);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(MATCH, dp(12));
        lp.topMargin = dp(8);
        lp.bottomMargin = dp(4);
        parent.addView(b, lp);
        return b;
    }

    /** Jauge horizontale arrondie. */
    @SuppressLint("ViewConstructor") // créée en code uniquement, jamais depuis un XML
    static final class BarView extends View {
        private final Paint track = new Paint(Paint.ANTI_ALIAS_FLAG);
        private final Paint fill = new Paint(Paint.ANTI_ALIAS_FLAG);
        private final RectF rect = new RectF();
        private float fraction;

        BarView(Context c, int trackColor, int fillColor, float fraction) {
            super(c);
            track.setColor(trackColor);
            fill.setColor(fillColor);
            this.fraction = fraction;
            setImportantForAccessibility(IMPORTANT_FOR_ACCESSIBILITY_NO);
        }

        void setFraction(float f) {
            fraction = f;
            invalidate();
        }

        @Override
        protected void onDraw(Canvas c) {
            float h = getHeight();
            float r = h / 2f;
            rect.set(0, 0, getWidth(), h);
            c.drawRoundRect(rect, r, r, track);
            float f = Math.max(0f, Math.min(1f, fraction));
            if (f > 0) {
                rect.set(0, 0, Math.max(h, getWidth() * f), h);
                c.drawRoundRect(rect, r, r, fill);
            }
        }
    }
}
