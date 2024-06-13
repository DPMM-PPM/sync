<h1>Description</h1>

Ce plugin ajoute une tache CRON au planificateur de taches d'ILIAS.
Il permet :<ul>
	<li> de gérer plus finement la date d'éxecution de la tâche (un jour de la semaine ou tous les jours).</li>
	<li> de mettre à jours les champs d'un utilisateur identifié par openidConnect sur la base d'un annuaire LDAP.</li>
	<li> de gérer la pagination d'un LDAP.</li></ul>
	
<h1>Prérequis</h1>

Si la fonction de synchonisation openidConnect est activée, il faut <b>qu'un seul LDAP</b> soit déclaré et actif dans l'administration d'ILIAS.<br>
La tâche CRON ajoutée par le plugin peut rentrer en conflit avec la tache CRON "synchroniser les utilisateurs LDAP" native à ILIAS, il est fortement conseillé de <b>désactiver</b> cette dernière.

<h1>Installation</h1>

Après avoir téléchargé le plugin (fichier Zip) à partir de GitHub, décompresser l'archive et renommer le dossier (retirer le suffixe de branche, ex -master).<br>
Copier ce dossier dans l'arborescence de votre installation ILIAS, à l'emplacement suivant (créer les dossiers et sous-dossiers) :<br> Customizing/global/plugins/Services/Cron/CronKook<br>
Aller dans ILIAS et se connecter avec un compte administrateur. se rendre dans "Administration > Extending ILIAS > Plugins"<br>
Cliquer sur "Installer" pour le plugin sync<br>
Cliquer sur "Activer" pour le plugin sync<br>
Cliquer sur "Rafraichir" pour actualiser la traduction<br>
Il n'y a pas d'options de configuration pour ce plugin.<br>
Une tache cron a du être ajoutée à la liste des tâches existantes.<br>

<h1>Utilisation</h1>
