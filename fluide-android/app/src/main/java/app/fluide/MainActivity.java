package app.fluide;

import android.Manifest;
import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.os.storage.StorageManager;
import android.provider.Settings;
import android.text.TextUtils;
import android.util.Log;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import java.util.concurrent.Callable;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.function.Consumer;

public class MainActivity extends Activity {
    static final int TAB_ANALYSE = 0, TAB_REGLAGES = 1, TAB_NETTOYAGE = 2, TAB_MESURE = 3, TAB_VRAI_FAUX = 4;
    static final String[] TAB_NAMES = {"Analyse", "Réglages", "Nettoyage", "Mesure", "Vrai/Faux"};

    static final int REQ_TEST = 1, REQ_CLEAR_CACHE = 2, REQ_STORAGE = 3;

    private static final String TAG = "Fluide";
    private static final int MATCH = ViewGroup.LayoutParams.MATCH_PARENT;
    private static final int WRAP = ViewGroup.LayoutParams.WRAP_CONTENT;

    Ui ui;
    private LinearLayout content;
    private ScrollView scroll;
    private final TextView[] tabs = new TextView[TAB_NAMES.length];
    private int currentTab = TAB_ANALYSE;
    private int generation;
    private boolean scrollTopOnResume;

    final ExecutorService bg = Executors.newSingleThreadExecutor();
    final Handler main = new Handler(Looper.getMainLooper());

    /** Dernière recherche de fichiers, conservée pendant la session (évite de tout relancer). */
    volatile FileScanner.Result lastFiles;
    /** Choix d'affichage de la liste d'applis : 0 = inutilisées, 1 = toutes par taille. */
    int appsView;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        ui = new Ui(this);
        Ui.edgeToEdge(this);
        if (savedInstanceState != null) currentTab = savedInstanceState.getInt("tab", TAB_ANALYSE);

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(ui.bg);

        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.VERTICAL);
        TextView title = ui.text(header, "Fluide", 28, ui.text, ui.bold);
        title.setContentDescription("Fluide");
        ui.text(header, "Diagnostic, réglages et mesure, sans magie", 14, ui.text2, null);
        root.addView(header, new LinearLayout.LayoutParams(MATCH, WRAP));

        scroll = new ScrollView(this);
        scroll.setClipToPadding(false);
        scroll.setFillViewport(true);
        content = new LinearLayout(this);
        content.setOrientation(LinearLayout.VERTICAL);
        content.setPadding(ui.dp(16), ui.dp(8), ui.dp(16), ui.dp(24));
        scroll.addView(content, new ScrollView.LayoutParams(MATCH, WRAP));
        root.addView(scroll, new LinearLayout.LayoutParams(MATCH, 0, 1));

        LinearLayout nav = new LinearLayout(this);
        nav.setOrientation(LinearLayout.HORIZONTAL);
        nav.setBackgroundColor(ui.surface);
        nav.setElevation(ui.dp(8));
        for (int i = 0; i < TAB_NAMES.length; i++) {
            final int idx = i;
            TextView t = ui.text(null, TAB_NAMES[i], 12.5f, ui.text2, ui.medium);
            t.setGravity(Gravity.CENTER);
            t.setSingleLine(true);
            t.setEllipsize(TextUtils.TruncateAt.END); // très grandes polices : « Nettoy… » plutôt que coupé net
            t.setPadding(ui.dp(2), ui.dp(8), ui.dp(2), ui.dp(8));
            t.setMinHeight(ui.dp(56));
            t.setClickable(true);
            t.setFocusable(true);
            t.setOnClickListener(v -> showTab(idx));
            tabs[i] = t;
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(0, WRAP, 1);
            lp.setMargins(ui.dp(3), ui.dp(6), ui.dp(3), ui.dp(6));
            nav.addView(t, lp);
        }
        root.addView(nav, new LinearLayout.LayoutParams(MATCH, WRAP));

        root.setOnApplyWindowInsetsListener((v, in) -> {
            int[] s = Ui.systemInsets(in);
            root.setPadding(s[0], 0, s[2], 0);
            header.setPadding(ui.dp(20), s[1] + ui.dp(14), ui.dp(20), ui.dp(8));
            nav.setPadding(ui.dp(6), 0, ui.dp(6), s[3]);
            return in;
        });
        setContentView(root);
    }

    @Override
    protected void onResume() {
        super.onResume();
        // Retour des Paramètres (autorisation accordée, appli désinstallée…) : on relit tout.
        render(scrollTopOnResume);
        scrollTopOnResume = false;
    }

    @Override
    protected void onSaveInstanceState(Bundle out) {
        super.onSaveInstanceState(out);
        out.putInt("tab", currentTab);
    }

    @Override
    protected void onDestroy() {
        bg.shutdownNow();
        super.onDestroy();
    }

    int currentTab() {
        return currentTab;
    }

    void showTab(int tab) {
        currentTab = tab;
        render(true);
    }

    /** Reconstruit l'onglet courant (en gardant la position de défilement si demandé). */
    void refresh() {
        render(false);
    }

    private void render(boolean scrollTop) {
        generation++;
        for (int i = 0; i < tabs.length; i++) {
            boolean sel = i == currentTab;
            tabs[i].setTextColor(sel ? ui.accent : ui.text2);
            tabs[i].setBackground(sel ? ui.rounded(ui.accentSoft, 14) : null);
            tabs[i].setSelected(sel);
            tabs[i].setContentDescription(TAB_NAMES[i] + (sel ? ", onglet sélectionné" : ", onglet"));
        }
        int y = scroll.getScrollY();
        content.removeAllViews();
        switch (currentTab) {
            case TAB_REGLAGES: TabReglages.build(this, content); break;
            case TAB_NETTOYAGE: TabNettoyage.build(this, content); break;
            case TAB_MESURE: TabMesure.build(this, content); break;
            case TAB_VRAI_FAUX: TabVraiFaux.build(this, content); break;
            default: TabAnalyse.build(this, content); break;
        }
        if (scrollTop) scroll.scrollTo(0, 0);
        else scroll.post(() -> scroll.scrollTo(0, y));
    }

    /**
     * Travail lent hors du fil principal ; le résultat (null en cas d'erreur) n'est livré
     * que si l'onglet n'a pas été reconstruit entre-temps.
     */
    <T> void runBg(Callable<T> work, Consumer<T> done) {
        final int gen = generation;
        bg.execute(() -> {
            T r;
            try {
                r = work.call();
            } catch (Throwable t) {
                Log.w(TAG, "Tâche en arrière-plan échouée", t);
                r = null;
            }
            final T res = r;
            main.post(() -> {
                if (gen == generation && !isFinishing() && !isDestroyed()) done.accept(res);
            });
        });
    }

    /** Pour les tests : attend la fin des tâches en arrière-plan déjà lancées. */
    boolean awaitBackground(long millis) {
        try {
            return bg.submit(() -> { }).get(millis, TimeUnit.MILLISECONDS) == null;
        } catch (Exception e) {
            return false;
        }
    }

    // ------------------------------------------------------------------ actions

    void handleAction(int action) {
        switch (action) {
            case Diagnostics.ACT_TAB_CLEAN: showTab(TAB_NETTOYAGE); break;
            case Diagnostics.ACT_TAB_TWEAKS: showTab(TAB_REGLAGES); break;
            case Diagnostics.ACT_TAB_MEASURE: showTab(TAB_MESURE); break;
            case Diagnostics.ACT_BATTERY: openSettings(Settings.ACTION_BATTERY_SAVER_SETTINGS, null); break;
            case Diagnostics.ACT_DISPLAY: openSettings(Settings.ACTION_DISPLAY_SETTINGS, null); break;
            default: break;
        }
    }

    void startTest() {
        startActivityForResult(new Intent(this, FrameTestActivity.class), REQ_TEST);
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == REQ_TEST) {
            currentTab = TAB_MESURE;
            scrollTopOnResume = true;
            toast(resultCode == RESULT_OK ? "Mesure enregistrée" : "Mesure interrompue : relancez-la sans toucher l'écran");
        } else if (requestCode == REQ_CLEAR_CACHE && resultCode == RESULT_OK) {
            toast("Cache des applis vidé");
        }
        // onResume suit et reconstruit l'onglet.
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == REQ_STORAGE) refresh();
    }

    /** Ouvre un écran des Paramètres ; à défaut, l'accueil des Paramètres. */
    void openSettings(String action, Uri data) {
        Intent i = new Intent(action);
        if (data != null) i.setData(data);
        try {
            startActivity(i);
        } catch (ActivityNotFoundException | SecurityException e) {
            try {
                startActivity(new Intent(Settings.ACTION_SETTINGS));
            } catch (ActivityNotFoundException e2) {
                toast("Impossible d'ouvrir les Paramètres");
            }
        }
    }

    void openAppInfo(String pkg) {
        openSettings(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:" + pkg));
    }

    void uninstall(String pkg) {
        try {
            startActivity(new Intent(Intent.ACTION_DELETE, Uri.parse("package:" + pkg)));
        } catch (ActivityNotFoundException e) {
            openAppInfo(pkg);
        }
    }

    void openDevOptions() {
        boolean on = Settings.Global.getInt(getContentResolver(), Settings.Global.DEVELOPMENT_SETTINGS_ENABLED, 0) == 1;
        if (on) {
            openSettings(Settings.ACTION_APPLICATION_DEVELOPMENT_SETTINGS, null);
        } else {
            toast("Touchez 7 fois « Numéro de build » pour activer les options pour les développeurs");
            openSettings(Settings.ACTION_DEVICE_INFO_SETTINGS, null);
        }
    }

    void openUsageAccess() {
        if (Build.VERSION.SDK_INT >= 29) {
            try {
                startActivity(new Intent(Settings.ACTION_USAGE_ACCESS_SETTINGS, Uri.parse("package:" + getPackageName())));
                return;
            } catch (ActivityNotFoundException | SecurityException ignored) {
                // certains fabricants n'acceptent pas la version ciblée
            }
        }
        openSettings(Settings.ACTION_USAGE_ACCESS_SETTINGS, null);
    }

    void requestFileAccess() {
        if (Build.VERSION.SDK_INT >= 30) {
            try {
                startActivity(new Intent(Settings.ACTION_MANAGE_APP_ALL_FILES_ACCESS_PERMISSION,
                        Uri.parse("package:" + getPackageName())));
            } catch (ActivityNotFoundException e) {
                openSettings(Settings.ACTION_MANAGE_ALL_FILES_ACCESS_PERMISSION, null);
            }
        } else {
            requestPermissions(new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE}, REQ_STORAGE);
        }
    }

    void openFreeUpSpace() {
        try {
            startActivity(new Intent(StorageManager.ACTION_MANAGE_STORAGE));
        } catch (ActivityNotFoundException | SecurityException e) {
            openSettings(Settings.ACTION_INTERNAL_STORAGE_SETTINGS, null);
        }
    }

    /** Android 11+ : fenêtre du système qui vide le cache de toutes les applis (confirmation demandée). */
    void clearAllCaches() {
        if (Build.VERSION.SDK_INT < 30) return;
        try {
            startActivityForResult(new Intent(StorageManager.ACTION_CLEAR_APP_CACHE), REQ_CLEAR_CACHE);
        } catch (ActivityNotFoundException | SecurityException e) {
            toast("Ce téléphone ne propose pas cette fonction : passez par le script PC (option 11)");
        }
    }

    void copy(String label, String text) {
        ClipboardManager cm = getSystemService(ClipboardManager.class);
        if (cm == null) return;
        cm.setPrimaryClip(ClipData.newPlainText(label, text));
        // Android 13+ affiche déjà sa propre confirmation.
        if (Build.VERSION.SDK_INT < 33) toast("Copié dans le presse-papiers");
    }

    void share(String subject, String text) {
        Intent s = new Intent(Intent.ACTION_SEND)
                .setType("text/plain")
                .putExtra(Intent.EXTRA_SUBJECT, subject)
                .putExtra(Intent.EXTRA_TEXT, text);
        try {
            startActivity(Intent.createChooser(s, "Envoyer vers…"));
        } catch (ActivityNotFoundException e) {
            toast("Aucune appli pour partager");
        }
    }

    void toast(String s) {
        Toast.makeText(this, s, Toast.LENGTH_LONG).show();
    }

    /** Petit bouton « Copier » + « Envoyer » sous un bloc de commandes. */
    void commandActions(ViewGroup parent, String label, String commands) {
        LinearLayout r = ui.buttonRow(parent);
        ui.smallButton(r, "Copier", Ui.SECONDARY, v -> copy(label, commands));
        ui.smallButton(r, "Envoyer vers le PC", Ui.QUIET, v -> share("Fluide : " + label, commands));
    }

    View.OnClickListener go(int tab) {
        return v -> showTab(tab);
    }
}
