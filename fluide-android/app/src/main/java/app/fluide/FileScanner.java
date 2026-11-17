package app.fluide;

import android.Manifest;
import android.content.Context;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.Environment;

import java.io.File;
import java.nio.file.Files;
import java.util.ArrayDeque;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

/** Recherche des gros fichiers et des fichiers d'installation (APK) oubliés. */
final class FileScanner {
    static final long BIG = 100L * 1000 * 1000; // 100 Mo
    static final int MAX_RESULTS = 60;
    static final int MAX_ENTRIES = 400_000;

    static final class Item {
        final File file;
        final long size;
        final boolean apk;
        final String folder; // chemin du dossier, relatif à la racine du stockage

        Item(File file, long size, boolean apk, String folder) {
            this.file = file;
            this.size = size;
            this.apk = apk;
            this.folder = folder;
        }

        String kind() {
            if (apk) return "APK";
            String n = file.getName().toLowerCase(Locale.ROOT);
            int dot = n.lastIndexOf('.');
            String ext = dot >= 0 && dot < n.length() - 1 ? n.substring(dot + 1) : "";
            if (ext.length() > 4 || ext.isEmpty()) return "Fichier";
            return ext.toUpperCase(Locale.ROOT);
        }
    }

    static final class Result {
        final List<Item> items = new ArrayList<>();
        long total;
        int scanned;
        boolean truncated;
    }

    private FileScanner() {}

    @SuppressWarnings("deprecation")
    static boolean hasAccess(Context c) {
        try {
            if (Build.VERSION.SDK_INT >= 30) return Environment.isExternalStorageManager();
            return c.checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE) == PackageManager.PERMISSION_GRANTED;
        } catch (RuntimeException e) {
            return false; // stockage partagé indisponible : on considère l'accès comme non accordé
        }
    }

    @SuppressWarnings("deprecation")
    static File root() {
        return Environment.getExternalStorageDirectory();
    }

    static boolean isApk(String lowerName) {
        return lowerName.endsWith(".apk") || lowerName.endsWith(".apks")
                || lowerName.endsWith(".xapk") || lowerName.endsWith(".apkm");
    }

    /** Lien symbolique, ou nom illisible : dans les deux cas on ne descend pas dans ce dossier. */
    static boolean isLink(File f) {
        try {
            return Files.isSymbolicLink(f.toPath());
        } catch (RuntimeException e) {
            return true;
        }
    }

    /** Parcours itératif (pas de récursion profonde), borné en nombre d'entrées et en durée. */
    static Result scan(File root, long maxMillis) {
        Result r = new Result();
        long deadline = System.currentTimeMillis() + maxMillis;
        ArrayDeque<File> stack = new ArrayDeque<>();
        stack.push(root);
        List<Item> found = new ArrayList<>();
        String rootPath = root.getAbsolutePath();

        while (!stack.isEmpty()) {
            if (r.scanned >= MAX_ENTRIES || System.currentTimeMillis() > deadline) {
                r.truncated = true;
                break;
            }
            File dir = stack.pop();
            File[] children = dir.listFiles();
            if (children == null) continue;
            for (File f : children) {
                r.scanned++;
                if (f.isDirectory()) {
                    // « Android/ » (données des applis) est protégé et hors sujet ; on évite aussi les liens.
                    if (dir.equals(root) && f.getName().equals("Android")) continue;
                    if (isLink(f)) continue;
                    stack.push(f);
                } else {
                    long len = f.length();
                    boolean apk = isApk(f.getName().toLowerCase(Locale.ROOT));
                    if ((apk && len > 0) || len >= BIG) {
                        String folder = dir.getAbsolutePath();
                        folder = folder.length() > rootPath.length() ? folder.substring(rootPath.length() + 1) : "";
                        found.add(new Item(f, len, apk, folder));
                    }
                }
            }
        }
        found.sort((a, b) -> Long.compare(b.size, a.size));
        for (Item it : found) {
            if (r.items.size() >= MAX_RESULTS) break;
            r.items.add(it);
            r.total += it.size;
        }
        return r;
    }
}
