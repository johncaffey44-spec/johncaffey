# La bibliothèque WireGuard appelle du code natif (JNI) : on garde ses classes intactes.
-keep class com.wireguard.** { *; }
-dontwarn com.wireguard.**
