Description

Ce plugin ajoute une tache CRON au planificateur de taches d'ILIAS.
Il permet :
	- de gérer plus finement la date d'éxecution de la tâche (un jour de la semaine ou tous les jours).
	- de mettre à jours les champs d'un utilisateur identifié par openidConnect sur la base d'un annuaire LDAP.
	- de gérer la pagination d'un LDAP.
	
Prérequis

Si la fonction de synchonisation openidConnect est activée, il faut qu'un seul LDAP soit déclaré et actif dans l'administration d'ILIAS.
La tâche CRON ajoutée par le plugin peut rentrer en conflit avec la tache CRON "synchroniser les utilisateurs LDAP" native à ILIAS, il est fortement conseillé de désactiver cette dernière.

Installation

Après avoir téléchargé le plugin (fichier Zip) à partir de GitHub, décompresser l'archive et renommer le dossier (retirer le suffixe de branche, ex -master).
    Copy the Flashcards directory to your ILIAS installation at the followin path (create subdirectories, if neccessary): Customizing/global/plugins/Services/Repository/RepositoryObject/
    Copier ce dossier dans l'arborescence de votre installation ILIAS, à l'emplacement suivant (créer les dossiers et sous-dossiers) : Customizing/global/plugins/Services/Cron/CronKook
    Aller dans ILIAS et se connecter avec un compte administrateur. se rendre dans "Administration > Extending ILIAS > Plugins"
    Cliquer sur "Installer" pour le plugin sync
    Cliquer sur "Activer" pour le plugin sync
    Cliquer sur "Raffraichir" pour actualiser la traduction
    Il n'y a pas d'options de configuration pour ce plugin.
    Une tache cron a du être ajoutée à la liste des tâches existantes.

Utilisation
