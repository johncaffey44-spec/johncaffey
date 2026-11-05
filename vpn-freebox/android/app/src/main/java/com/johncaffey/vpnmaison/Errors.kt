package com.johncaffey.vpnmaison

import com.wireguard.android.backend.BackendException
import com.wireguard.config.BadConfigException

/** Traduit les erreurs techniques en messages compréhensibles. */
object Errors {
    fun describe(e: Throwable): String = when (e) {
        is BackendException -> when (e.reason) {
            BackendException.Reason.VPN_NOT_AUTHORIZED ->
                "Android n'a pas encore autorisé l'application à créer un VPN. Appuyez à nouveau sur le bouton et acceptez la demande « Connexion VPN »."
            BackendException.Reason.DNS_RESOLUTION_FAILURE ->
                "Impossible de trouver l'adresse de votre Freebox sur Internet. Vérifiez que le téléphone a bien Internet (4G ou Wi-Fi), puis réessayez."
            BackendException.Reason.UNABLE_TO_START_VPN, BackendException.Reason.TUN_CREATION_ERROR ->
                "Android a refusé de démarrer le VPN. Un autre VPN est peut-être actif ou réglé en « VPN permanent » : Paramètres > Réseau > VPN."
            BackendException.Reason.GO_ACTIVATION_ERROR_CODE, BackendException.Reason.TUNNEL_MISSING_CONFIG ->
                "La configuration semble abîmée. Réimportez-la depuis Freebox OS (QR code)."
            else -> "Le VPN n'a pas pu démarrer (${e.reason})."
        }
        is BadConfigException ->
            "Ce n'est pas une configuration WireGuard valide (problème dans « ${e.section} / ${e.location} »). Réimportez le QR code ou le fichier .conf fourni par Freebox OS."
        is InvalidConfigException -> e.message ?: "Configuration invalide."
        else -> "Erreur inattendue : ${e.javaClass.simpleName}${e.message?.let { " – $it" } ?: ""}"
    }
}

class InvalidConfigException(message: String) : Exception(message)
