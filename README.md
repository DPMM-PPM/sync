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
Copier ce dossier dans l'arborescence de votre installation ILIAS, à l'emplacement suivant (créer les dossiers et sous-dossiers) :<br><div align="center">Customizing/global/plugins/Services/Cron/CronKook</div><br>
Aller dans ILIAS et se connecter avec un compte administrateur. se rendre dans "Administration > Extending ILIAS > Plugins"<br>
Cliquer sur "Installer" pour le plugin sync<br>
Cliquer sur "Activer" pour le plugin sync<br>
Cliquer sur "Rafraichir" pour actualiser la traduction<br>
Il n'y a pas d'options de configuration pour ce plugin.<br>
Une tache cron a du être ajoutée à la liste des tâches existantes.

<h1>Utilisation</h1>
<h2>Planification de la tâche</h2>
Choisir un jour de la semaine à laquelle vous souhaitez executer la tâche Cron. la tâche peut être executée tous les jours en sélectionnant "tous les jours".
<h2>Synchronisation openidConnect</h2>
Cette fonctionnalité permet de remplir et de maintenir à jour les champs standards d'une fiche utilisateur sur la base des informations contenues dans un annuaire LDAP.<br>
Pour utiliser cette fonctionnalité, il faut qu'un LDAP <b>et qu'un seul</b> soit configué et actif dans la rubrique "utilisateurs->authentification->ldap".<br>
Si c'est le cas, lors de l'éxecution de la tâche, le LDAP sera lu, les utilisateurs dont le compte n'est pas encore créé dans la BDD d'ILIAS seront créés avec le mode d'authentification "oidc", les comptes oidc existants seront mis à jour avec les informations contenues dans le LDAP.
ATTENTION : Si des comptes LDAP existent avant l'éxecution de la tache CRON avec la case oidc activée, le compte existant sera toujours taggé LDAP et un second compte oidc sera créé. Il peut en résulter une perte des données de suivi de ce compte. Pour éviter ceci, il faut tout d'abord effectuer une requéte SQL pour changer le mode d'authentification de LDAP à oidc puis cocher la case oidc dans le plug-in et lancer la tâche CRON. Les comptes existants seront mis à jour et les comptes absents seront créé.
<h2>Gestion de la pagination LDAP</h2>
Depuis la version 6.20, ILIAS prend en charge les gros annuaires LDAP. Un système de pagination a été mis en place et la liste des utilisateurs du LDAP est organisée par pages de 100 utilisateurs.<br>
Cette fonction est interne au LDAP et disponible à partir du protocole LDAP V3.<br>
Si cette fonction n'est pas disponible ou fonctionne mal, il est possible de ne pas en tenir compte et de limiter le nombre d'utilisateur remonté à chaque requête. la requête d'interrogation du LDAP peut se faire selon 3 modes différents :<ol>
	<li>Par ordre alphabétique<br>
		Le filtre de requêtage va chercher tous les utilisateurs commençant par aa, puis ab, puis ac jusqu'à az puis ba, bb, jusquà zz</li>
	<li>Format dit "Annudef" (prenom.nom)<br>
 		Le filtre de requêtage va chercher tous les utilisateurs dont la partie du login située après le prénom commençe par .aa, puis .ab, puis .ac jusqu'à .az puis .ba, .bb, jusquà .zz</li>
	<li>Format dit "Dr-cpt" (p.nom)<br>
 		Le filtre de requêtage va chercher tous les utilisateurs dont le login commençe par a.a, puis a.b, puis a.c jusqu'à a.z puis b.a, b.b, jusquà z.z</li>
</ol>
