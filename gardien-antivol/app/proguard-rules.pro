# Règles ProGuard — Gardien
# Le récepteur d'administration d'appareil est référencé depuis le manifeste et le XML de politiques.
-keep class com.d8.gardien.admin.GardienDeviceAdminReceiver { *; }
-keep class com.d8.gardien.service.** { *; }
