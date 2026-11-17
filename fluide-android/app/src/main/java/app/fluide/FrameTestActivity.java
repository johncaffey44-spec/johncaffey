package app.fluide;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Paint;
import android.graphics.Typeface;
import android.graphics.drawable.ColorDrawable;
import android.graphics.drawable.GradientDrawable;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.HandlerThread;
import android.os.Looper;
import android.os.PowerManager;
import android.text.TextUtils;
import android.view.Choreographer;
import android.view.Display;
import android.view.FrameMetrics;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.Window;
import android.view.WindowManager;
import android.widget.AbsListView;
import android.widget.BaseAdapter;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ListView;
import android.widget.TextView;

import java.util.Random;

/**
 * Test de fluidité : un fil d'actualité type (cartes, ombres, images) défile seul à vitesse
 * constante ; chaque image produite est chronométrée par le système (FrameMetrics).
 * 1,5 s d'échauffement non comptées, puis 8 s de mesure.
 *
 * L'appli est compilée en mode non débogable : ART l'exécute comme n'importe quelle appli
 * du Play Store, ce qui rend la mesure représentative.
 */
public class FrameTestActivity extends Activity implements Choreographer.FrameCallback {
    static final long WARMUP_NS = 1_500_000_000L;
    static final long MEASURE_NS = 8_000_000_000L;
    static final float SPEED_DP_PER_S = 1600f;
    static final int MIN_FRAMES = 30;

    private static final int MATCH = ViewGroup.LayoutParams.MATCH_PARENT;
    private static final int WRAP = ViewGroup.LayoutParams.WRAP_CONTENT;

    private Ui ui;
    private ListView list;
    private Ui.BarView progress;
    private final FrameStats stats = new FrameStats();
    private final Handler main = new Handler(Looper.getMainLooper());
    private HandlerThread metricsThread;
    private Window.OnFrameMetricsAvailableListener metricsListener;

    private long t0 = -1;
    private long lastFrame = -1;
    private volatile long windowStart = Long.MAX_VALUE;
    private volatile long windowEnd = Long.MAX_VALUE;
    private float carry;
    private float pxPerSecond;
    private float hzDuring;
    private boolean started;
    private boolean done;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        ui = new Ui(this);
        Ui.edgeToEdge(this);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        pxPerSecond = SPEED_DP_PER_S * ui.density;

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(ui.bg);

        list = new ListView(this);
        list.setDivider(null);
        list.setSelector(new ColorDrawable(Color.TRANSPARENT));
        list.setVerticalScrollBarEnabled(false);
        list.setOverScrollMode(View.OVER_SCROLL_NEVER);
        list.setClipToPadding(false);
        list.setAdapter(new FeedAdapter(this, ui));
        blockTouches(list);
        root.addView(list, new FrameLayout.LayoutParams(MATCH, MATCH));

        LinearLayout banner = new LinearLayout(this);
        banner.setOrientation(LinearLayout.VERTICAL);
        banner.setBackground(ui.rounded(ui.surface, 16));
        banner.setElevation(ui.dp(6));
        banner.setPadding(ui.dp(16), ui.dp(12), ui.dp(16), ui.dp(14));
        ui.text(banner, "Mesure en cours…", 16, ui.text, ui.medium);
        ui.text(banner, "Ne touchez pas l'écran pendant 10 secondes.", 13.5f, ui.text2, null);
        progress = new Ui.BarView(this, ui.surface2, ui.accent, 0f);
        LinearLayout.LayoutParams plp = new LinearLayout.LayoutParams(MATCH, ui.dp(5));
        plp.topMargin = ui.dp(10);
        banner.addView(progress, plp);
        FrameLayout.LayoutParams blp = new FrameLayout.LayoutParams(MATCH, WRAP, Gravity.TOP);
        blp.setMargins(ui.dp(16), ui.dp(12), ui.dp(16), 0);
        root.addView(banner, blp);

        root.setOnApplyWindowInsetsListener((v, in) -> {
            int[] s = Ui.systemInsets(in);
            blp.setMargins(ui.dp(16) + s[0], ui.dp(12) + s[1], ui.dp(16) + s[2], 0);
            banner.setLayoutParams(blp);
            list.setPadding(s[0], s[1] + ui.dp(110), s[2], s[3]);
            return in;
        });
        setContentView(root);
    }

    /** Un doigt sur l'écran fausserait la mesure : le défilement est entièrement automatique. */
    @SuppressLint("ClickableViewAccessibility")
    private static void blockTouches(View v) {
        v.setOnTouchListener((view, e) -> true);
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (started || done) return;
        started = true;
        metricsThread = new HandlerThread("fluide-metriques");
        metricsThread.start();
        metricsListener = (window, fm, dropped) -> {
            if (fm.getMetric(FrameMetrics.FIRST_DRAW_FRAME) == 1) return;
            long intended = fm.getMetric(FrameMetrics.INTENDED_VSYNC_TIMESTAMP);
            if (intended < windowStart || intended >= windowEnd) return;
            if (dropped > 0) stats.addLost(dropped);
            long deadline = Build.VERSION.SDK_INT >= 31 ? fm.getMetric(FrameMetrics.DEADLINE) : 0;
            stats.add(fm.getMetric(FrameMetrics.TOTAL_DURATION), deadline,
                    fm.getMetric(FrameMetrics.VSYNC_TIMESTAMP));
        };
        getWindow().addOnFrameMetricsAvailableListener(metricsListener, new Handler(metricsThread.getLooper()));
        // Laisse passer la première mise en page avant de démarrer le chronomètre.
        main.postDelayed(() -> {
            if (!done) Choreographer.getInstance().postFrameCallback(this);
        }, 400);
    }

    @Override
    public void doFrame(long now) {
        if (done) return;
        if (t0 < 0) {
            t0 = now;
            lastFrame = now;
            windowStart = t0 + WARMUP_NS;
            windowEnd = windowStart + MEASURE_NS;
        }
        float dt = Math.min(0.1f, (now - lastFrame) / 1e9f);
        lastFrame = now;
        float px = pxPerSecond * dt + carry;
        int step = (int) px;
        carry = px - step;
        if (step > 0) list.scrollListBy(step);
        progress.setFraction((now - t0) / (float) (WARMUP_NS + MEASURE_NS));
        if (hzDuring == 0f && now >= windowStart + MEASURE_NS / 2) hzDuring = currentRefresh();
        if (now >= windowEnd) {
            finishTest();
            return;
        }
        Choreographer.getInstance().postFrameCallback(this);
    }

    private void finishTest() {
        done = true;
        // Les dernières métriques arrivent avec un léger décalage.
        main.postDelayed(() -> {
            stopMetrics();
            double hz = hzDuring > 0 ? hzDuring : currentRefresh();
            FrameStats.Summary s = stats.summarize(hz);
            if (s.frames < MIN_FRAMES) {
                setResult(RESULT_CANCELED);
                finish();
                return;
            }
            ResultStore.add(this, toEntry(s, hz));
            setResult(RESULT_OK);
            finish();
        }, 350);
    }

    ResultStore.Entry toEntry(FrameStats.Summary s, double hz) {
        ResultStore.Entry e = new ResultStore.Entry();
        e.time = System.currentTimeMillis();
        e.frames = s.frames;
        e.latePct = s.latePct;
        e.fps = s.fps;
        e.hz = hz;
        e.p95 = s.p95;
        e.budgetMs = s.budgetMs;
        e.deadlineBased = s.deadlineBased;
        e.anim = SystemTweaks.readAnim(getContentResolver())[0];
        PowerManager pm = getSystemService(PowerManager.class);
        if (pm != null) {
            e.powerSave = pm.isPowerSaveMode();
            if (Build.VERSION.SDK_INT >= 29) e.thermal = pm.getCurrentThermalStatus();
        }
        e.temp = Diagnostics.batteryTemp(this);
        return e;
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (!done) {
            // Appel, rotation, retour… : mesure faussée, on l'abandonne.
            done = true;
            Choreographer.getInstance().removeFrameCallback(this);
            stopMetrics();
            setResult(RESULT_CANCELED);
            finish();
        }
    }

    @Override
    protected void onDestroy() {
        Choreographer.getInstance().removeFrameCallback(this);
        stopMetrics();
        super.onDestroy();
    }

    private void stopMetrics() {
        if (metricsListener != null) {
            try {
                getWindow().removeOnFrameMetricsAvailableListener(metricsListener);
            } catch (RuntimeException ignored) {
                // déjà retiré
            }
            metricsListener = null;
        }
        if (metricsThread != null) {
            metricsThread.quitSafely();
            metricsThread = null;
        }
    }

    @SuppressWarnings("deprecation")
    private float currentRefresh() {
        Display d = Build.VERSION.SDK_INT >= 30 ? getDisplay() : getWindowManager().getDefaultDisplay();
        return d == null ? 60f : d.getRefreshRate();
    }

    // ------------------------------------------------------------------ contenu du fil

    static final class Item {
        String name, initial, time, text;
        int color;
        boolean media;
        int[] gradient;
    }

    private static final String[] NAMES = {"Camille", "Léa", "Hugo", "Inès", "Lucas", "Manon", "Nathan",
            "Chloé", "Louis", "Sarah", "Jules", "Emma", "Adam", "Zoé", "Yanis", "Lina", "Rayan", "Jade"};

    private static final String[] TEXTS = {
            "Quelqu'un a des idées de balade pour ce week-end ?",
            "Photos du concert d'hier soir, quelle ambiance !",
            "Rappel : la réunion est déplacée à jeudi 14 h.",
            "Nouvelle recette testée ce midi, validée par toute la famille.",
            "Le train avait encore vingt minutes de retard ce matin.",
            "Qui est partant pour un foot dimanche ?",
            "Je viens de finir ce livre, je vous le recommande vivement.",
            "Pensez à arroser les plantes pendant mon absence.",
            "Coucher de soleil incroyable depuis la terrasse.",
            "Les inscriptions pour l'atelier sont ouvertes jusqu'à vendredi.",
    };

    private static final int[] COLORS = {0xFF2557D6, 0xFF0F9D58, 0xFFE8710A, 0xFFD93025,
            0xFF9334E6, 0xFF12B5CB, 0xFFF4B400, 0xFF5F6368};

    static Item[] generate(int count) {
        Random r = new Random(42); // même contenu à chaque test : comparaisons honnêtes
        Item[] out = new Item[count];
        for (int i = 0; i < count; i++) {
            Item it = new Item();
            it.name = NAMES[r.nextInt(NAMES.length)] + " " + (char) ('A' + r.nextInt(26)) + ".";
            it.initial = it.name.substring(0, 1);
            it.color = COLORS[r.nextInt(COLORS.length)];
            it.time = (1 + r.nextInt(59)) + " min";
            it.text = TEXTS[r.nextInt(TEXTS.length)] + " " + TEXTS[r.nextInt(TEXTS.length)];
            it.media = i % 4 == 2;
            if (it.media) it.gradient = new int[]{COLORS[r.nextInt(COLORS.length)], COLORS[r.nextInt(COLORS.length)]};
            out[i] = it;
        }
        return out;
    }

    static final class FeedAdapter extends BaseAdapter {
        private final Context c;
        private final Ui ui;
        private final Item[] items = generate(1500);

        FeedAdapter(Context c, Ui ui) {
            this.c = c;
            this.ui = ui;
        }

        @Override public int getCount() { return items.length; }
        @Override public Object getItem(int i) { return items[i]; }
        @Override public long getItemId(int i) { return i; }
        @Override public int getViewTypeCount() { return 2; }
        @Override public int getItemViewType(int i) { return items[i].media ? 1 : 0; }

        @Override
        public View getView(int i, View convert, ViewGroup parent) {
            View v = convert != null ? convert : create(getItemViewType(i));
            Holder h = (Holder) v.getTag();
            Item it = items[i];
            h.avatar.set(it.color, it.initial);
            h.name.setText(it.name);
            h.time.setText(it.time);
            h.text.setText(it.text);
            if (h.media != null) ((GradientDrawable) h.media.getBackground()).setColors(it.gradient);
            return v;
        }

        private View create(int type) {
            FrameLayout outer = new FrameLayout(c);
            outer.setLayoutParams(new AbsListView.LayoutParams(MATCH, WRAP));
            outer.setPadding(ui.dp(12), ui.dp(6), ui.dp(12), ui.dp(6));
            LinearLayout card = new LinearLayout(c);
            card.setOrientation(LinearLayout.VERTICAL);
            card.setBackground(ui.rounded(ui.surface, 16));
            card.setElevation(ui.dp(2));
            card.setPadding(ui.dp(14), ui.dp(12), ui.dp(14), ui.dp(12));
            outer.addView(card, new FrameLayout.LayoutParams(MATCH, WRAP));

            Holder h = new Holder();
            LinearLayout head = new LinearLayout(c);
            head.setOrientation(LinearLayout.HORIZONTAL);
            head.setGravity(Gravity.CENTER_VERTICAL);
            h.avatar = new AvatarView(c, ui.medium);
            int size = ui.dp(type == 0 ? 44 : 36);
            head.addView(h.avatar, new LinearLayout.LayoutParams(size, size));
            LinearLayout col = new LinearLayout(c);
            col.setOrientation(LinearLayout.VERTICAL);
            LinearLayout.LayoutParams clp = new LinearLayout.LayoutParams(0, WRAP, 1);
            clp.setMarginStart(ui.dp(12));
            head.addView(col, clp);
            LinearLayout top = new LinearLayout(c);
            top.setOrientation(LinearLayout.HORIZONTAL);
            h.name = ui.text(null, "", 15, ui.text, ui.medium);
            h.name.setSingleLine(true);
            h.name.setEllipsize(TextUtils.TruncateAt.END);
            top.addView(h.name, new LinearLayout.LayoutParams(0, WRAP, 1));
            h.time = ui.text(null, "", 12, ui.text2, null);
            top.addView(h.time, new LinearLayout.LayoutParams(WRAP, WRAP));
            col.addView(top, new LinearLayout.LayoutParams(MATCH, WRAP));
            card.addView(head, new LinearLayout.LayoutParams(MATCH, WRAP));

            h.text = ui.text(null, "", 14, ui.text2, null);
            h.text.setMaxLines(type == 0 ? 2 : 3);
            h.text.setEllipsize(TextUtils.TruncateAt.END);
            if (type == 0) {
                col.addView(h.text, new LinearLayout.LayoutParams(MATCH, WRAP));
            } else {
                h.media = new View(c);
                GradientDrawable g = new GradientDrawable(GradientDrawable.Orientation.TL_BR,
                        new int[]{ui.accent, ui.accentSoft});
                g.setCornerRadius(ui.dp(12));
                h.media.setBackground(g);
                LinearLayout.LayoutParams mlp = new LinearLayout.LayoutParams(MATCH, ui.dp(180));
                mlp.topMargin = ui.dp(10);
                card.addView(h.media, mlp);
                LinearLayout.LayoutParams tlp = new LinearLayout.LayoutParams(MATCH, WRAP);
                tlp.topMargin = ui.dp(8);
                card.addView(h.text, tlp);
            }
            outer.setTag(h);
            return outer;
        }
    }

    static final class Holder {
        AvatarView avatar;
        TextView name, time, text;
        View media;
    }

    /** Pastille ronde avec initiale, dessinée directement (comme les avatars des vraies applis). */
    @SuppressLint("ViewConstructor") // créée en code uniquement, jamais depuis un XML
    static final class AvatarView extends View {
        private final Paint circle = new Paint(Paint.ANTI_ALIAS_FLAG);
        private final Paint letter = new Paint(Paint.ANTI_ALIAS_FLAG);
        private String initial = "";

        AvatarView(Context c, Typeface tf) {
            super(c);
            letter.setColor(Color.WHITE);
            letter.setTextAlign(Paint.Align.CENTER);
            letter.setTypeface(tf);
        }

        void set(int color, String s) {
            circle.setColor(color);
            initial = s;
            invalidate();
        }

        @Override
        protected void onDraw(Canvas canvas) {
            float w = getWidth();
            float h = getHeight();
            float r = Math.min(w, h) / 2f;
            canvas.drawCircle(w / 2f, h / 2f, r, circle);
            letter.setTextSize(r);
            float y = h / 2f - (letter.descent() + letter.ascent()) / 2f;
            canvas.drawText(initial, w / 2f, y, letter);
        }
    }
}
