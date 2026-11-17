package app.fluide;

import android.widget.LinearLayout;

/** Onglet « Vrai/Faux » : les idées reçues sur la vitesse d'un téléphone, vérifiées. */
final class TabVraiFaux {
    static final int VRAI = Ui.GOOD, FAUX = Ui.URGENT, NUANCE = Ui.FIX;

    static final class Myth {
        final String claim;
        final int verdict;
        final String label;
        final String why;

        Myth(String claim, int verdict, String label, String why) {
            this.claim = claim;
            this.verdict = verdict;
            this.label = label;
            this.why = why;
        }
    }

    static final Myth[] MYTHS = {
            new Myth("« Vider la RAM accélère le téléphone »", FAUX, "Faux",
                    "Android garde exprès vos applis récentes en mémoire pour les rouvrir instantanément : de la"
                            + " RAM « libre » est de la RAM qui ne sert à rien. Quand la place manque, le système"
                            + " libère lui-même ce qui sert le moins. Un « nettoyeur de RAM » force des fermetures :"
                            + " les applis repartent de zéro (plus lent, plus de batterie) et certaines se relancent"
                            + " aussitôt d'elles-mêmes."),
            new Myth("« Fermer ses applis économise la batterie »", FAUX, "Faux, le plus souvent",
                    "Une appli inactive en arrière-plan ne consomme presque rien ; la relancer depuis zéro coûte"
                            + " davantage. Google comme Apple le déconseillent. Exception : une appli précise qui"
                            + " reste active (GPS, bug) ; restreignez-la dans sa fiche (Batterie › Restreinte)."),
            new Myth("« Vider le cache rend le téléphone plus rapide »", FAUX, "Faux, sauf stockage saturé",
                    "Le cache existe pour accélérer : miniatures, images et données déjà téléchargées. Le vider"
                            + " fait de la place, puis les applis le reconstruisent, plus lentement les premiers"
                            + " jours. À faire seulement quand le stockage est presque plein."),
            new Myth("« Les applis de nettoyage sont utiles »", FAUX, "Faux, en général",
                    "Beaucoup tournent en permanence, affichent de la publicité et collectent des données : elles"
                            + " consomment les ressources qu'elles prétendent libérer. Fluide n'a pas d'accès à"
                            + " Internet, aucun service en arrière-plan, et n'agit que quand vous touchez un bouton."),
            new Myth("« Les applis booster ou turbo jeu augmentent la puissance »", FAUX, "Faux",
                    "Une appli ordinaire ne peut pas changer la fréquence du processeur. Au mieux, elle ferme"
                            + " d'autres applis (voir plus haut). Les modes jeu intégrés par le fabricant, eux, ont"
                            + " des droits système et peuvent réellement agir."),
            new Myth("« La RAM virtuelle double la mémoire »", FAUX, "Faux",
                    "« RAM Plus », « extension de mémoire »… c'est du stockage utilisé en appoint, des dizaines de"
                            + " fois plus lent que la vraie RAM. Cela garde plus d'applis en arrière-plan sans rien"
                            + " accélérer. Sur un téléphone au stockage lent ou presque plein, la réduire peut même"
                            + " aider."),
            new Myth("« Un stockage presque plein ralentit le téléphone »", VRAI, "Vrai",
                    "La mémoire flash a besoin d'espace libre pour écrire vite et s'entretenir. En dessous"
                            + " d'environ 10 % libres, le ralentissement devient sensible et les mises à jour"
                            + " peuvent échouer. Visez 15 à 20 % d'espace libre."),
            new Myth("« Compiler les applis accélère le téléphone »", VRAI, "Vrai, après une mise à jour",
                    "Après une mise à jour, le code des applis tourne d'abord dans un mode moins optimisé, d'où"
                            + " des saccades passagères. Android le recompile la nuit, téléphone en charge. Le"
                            + " script PC le fait tout de suite ; inutile de le répéter."),
            new Myth("« Désactiver les applis préinstallées aide »", VRAI, "Vrai, selon la marque",
                    "Certaines surcouches installent des services qui tournent en arrière-plan. Les désactiver"
                            + " libère de la mémoire et réduit l'activité de fond. Gain variable, et seulement avec"
                            + " une liste sûre : désactiver au hasard peut casser des fonctions."),
            new Myth("« Réduire les animations rend le téléphone plus fluide »", NUANCE, "Nuancé",
                    "Il devient plus réactif : on attend moins. Mais la fluidité, c'est l'absence d'images sautées"
                            + " pendant un mouvement, et cela dépend du matériel. 0,5x est le bon compromis ; à zéro,"
                            + " on perd les transitions qui aident l'œil à suivre."),
            new Myth("« 120 Hz rend tout plus fluide »", VRAI, "Vrai, avec un coût",
                    "Deux fois plus d'images qu'en 60 Hz : les défilements sont plus nets. Le prix est"
                            + " l'autonomie ; le mode adaptatif, qui redescend quand l'image est fixe, est le bon"
                            + " compromis."),
            new Myth("« Un iPhone est fluide grâce à un iOS mieux optimisé »", NUANCE, "Nuancé",
                    "Apple conçoit puce, système et écran ensemble, soigne ses animations (elles suivent le doigt"
                            + " et s'interrompent sans à-coup) et ne vend pas d'iPhone d'entrée de gamme. On compare"
                            + " souvent un iPhone haut de gamme à un Android d'entrée de gamme ; à gamme égale"
                            + " (Pixel, Galaxy S…), l'écart est faible. Et les iPhone non-Pro sont restés à 60 Hz"
                            + " jusqu'à l'iPhone 16."),
            new Myth("« Forcer le rendu GPU ou le MSAA 4x accélère »", FAUX, "Faux",
                    "Les applis sont dessinées par le GPU par défaut depuis des années. « Forcer MSAA 4x » ajoute"
                            + " du travail à chaque image : moins de fluidité, plus de consommation."),
            new Myth("« Redémarrer régulièrement aide »", NUANCE, "En partie",
                    "Un redémarrage par semaine élimine les fuites de mémoire de certaines applis. Effet réel mais"
                            + " modeste ; inutile tous les jours."),
            new Myth("« Réinitialiser, ça marche »", VRAI, "Vrai, en dernier recours",
                    "Sur un téléphone de plusieurs années, une réinitialisation (après sauvegarde) supprime restes"
                            + " d'applis et réglages accumulés : c'est souvent le gain le plus net. Ne réinstallez"
                            + " ensuite que ce qui sert."),
    };

    private TabVraiFaux() {}

    static void build(MainActivity a, LinearLayout root) {
        Ui ui = a.ui;
        LinearLayout c = ui.card(root);
        ui.title(c, "Ce qui fait vraiment la fluidité");
        ui.bullets(c,
                "Le processeur et surtout le GPU, qui calcule les images.",
                "La mémoire vive : assez pour garder vos applis ouvertes.",
                "Le stockage : l'UFS des téléphones milieu et haut de gamme est bien plus rapide que l'eMMC"
                        + " des entrées de gamme.",
                "L'écran : 60, 90 ou 120 Hz, et sa réactivité au toucher.",
                "Le refroidissement : un téléphone qui chauffe se bride.",
                "Le soin du fabricant : surcouche légère, mises à jour, applis préinstallées.");
        ui.small(c, "Les cinq premiers points sont matériels. Une appli ne peut agir que sur le dernier, sur vos"
                + " réglages et sur ce que vous installez.");

        for (Myth m : MYTHS) {
            LinearLayout card = ui.card(root);
            ui.pill(card, m.label, m.verdict);
            ui.gap(card, 8);
            ui.text(card, m.claim, 16, ui.text, ui.medium);
            ui.gap(card, 4);
            ui.text(card, m.why, 14.5f, ui.text2, null);
        }
    }
}
