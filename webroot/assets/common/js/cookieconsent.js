import 'vanilla-cookieconsent/dist/cookieconsent.css'; // Import the CSS
import { run } from 'vanilla-cookieconsent';

document.addEventListener('DOMContentLoaded', () => {

	let base_url = window.formr?.site_url || '';
	if(window.formr?.run_url && window.location.href.includes(window.formr.run_url)) {
		base_url = window.formr?.run_url;
	}

run({
	cookie: {
		name: 'formrcookieconsent',
		domain: '',
		path: '/',
		secure: true,
		expiresAfterDays: 730,
		sameSite: 'Lax',
		useLocalStorage: false
	},	
	guiOptions: {
		consentModal: {
			layout: "bar inline",
			position: "bottom",
			equalWeightButtons: true,
			flipButtons: true
		},
		preferencesModal: {
			layout: "box",
			position: "right",
			equalWeightButtons: true,
			flipButtons: false
		}
	},
	categories: {
		necessary: {
			readOnly: true
		},
		functionality: {}
	},
	language: {
		default: "en",
		autoDetect: "browser",
		translations: {
			de: {
				consentModal: {
					title: "Dieses Gerät wieder erkennen?",
					description: "Wir nutzen keine Cookies von anderen Parteien, für Werbung oder Analytics. Wenn Sie es erlauben, wird dieses Gerät auch, wenn Sie den Browser schließen, wieder erkannt und Sie machen da weiter, wo Sie aufgehört haben.",
					acceptAllBtn: "Funktionale Cookies akzeptieren",
					acceptNecessaryBtn: "Nicht erlauben",
					showPreferencesBtn: "Einstellungen verwalten",
					footer: "<a href=\"" + base_url + "privacy_policy\">Datenschutz</a>\n<a href=\"" + base_url + "terms_of_service\">Bedingungen und Konditionen</a>"
				},
				preferencesModal: {
					title: "Präferenzen für die Zustimmung",
					acceptAllBtn: "Alle akzeptieren",
					acceptNecessaryBtn: "Alle ablehnen",
					savePreferencesBtn: "Einstellungen speichern",
					closeIconLabel: "Dialogfeld schließen",
					serviceCounterLabel: "Dienstleistungen",
					sections: [
						{
							title: "Verwendung von Cookies",
							description: "Wir nutzen keine Cookies von anderen Parteien, für Werbung oder Analytics. Wenn Sie es erlauben, wird dieses Gerät auch, wenn Sie den Browser schließen, wieder erkannt und Sie machen da weiter, wo Sie aufgehört haben. Ansonsten werden nur Session-Cookies gesetzt, die automatisch gelöscht werden, sobald Sie den Browser schließen (oder wenn Sie sich ausloggen)."
						},
						{
							title: "Streng notwendige Cookies <span class=\"pm__badge\">Immer Aktiviert</span>",
							description: "Ohne Session-Cookies ist es nicht möglich Ihren Studienfortschritt zu speichern.",
							linkedCategory: "necessary"
						},
						{
							title: "Funktionalitäts Cookies",
							description: "Diese Cookies ermöglichen es uns Ihr Gerät wiederzuerkennen. So können Sie die Studie unterbrechen und später dort weitermachen, wo Sie aufgehört haben.",
							linkedCategory: "functionality"
						}
					]
				}
			},
			en: {
				consentModal: {
					title: "Recognize this device again?",
					description: "We do not use cookies from other parties for advertising or analytics. If you allow it, this device will be recognized even if you close the browser, and you can continue where you left off.",
					acceptAllBtn: "Accept functional cookies",
					acceptNecessaryBtn: "Do not allow",
					showPreferencesBtn: "Manage settings",
					footer: "<a href=\"" + base_url + "privacy_policy\">Privacy Policy</a>\n<a href=\"" + base_url + "terms_of_service\">Terms and Conditions</a>"
				},
				preferencesModal: {
					title: "Consent Preferences",
					acceptAllBtn: "Accept all",
					acceptNecessaryBtn: "Reject all",
					savePreferencesBtn: "Save settings",
					closeIconLabel: "Close dialog",
					serviceCounterLabel: "Service|Services",
					sections: [
						{
							title: "Use of Cookies",
							description: "We do not use cookies from other parties for advertising or analytics. If you allow it, this device will be recognized even if you close the browser, and you can continue where you left off. Otherwise, only session cookies are set, which are automatically deleted as soon as you close the browser (or when you log out)."
						},
						{
							title: "Strictly Necessary Cookies <span class=\"pm__badge\">Always Enabled</span>",
							description: "Without session cookies, it is not possible to save your study progress.",
							linkedCategory: "necessary"
						},
						{
							title: "Functionality Cookies",
							description: "These cookies allow us to recognize your device again. This way, you can interrupt the study and continue later where you left off.",
							linkedCategory: "functionality"
						}
					]
				}
			},
			fr: {
				consentModal: {
					title: "Reconnaître à nouveau cet appareil ?",
					description: "Nous n\'utilisons pas de cookies de tiers pour la publicité ou l\'analyse. Si vous l\'autorisez, cet appareil sera reconnu même si vous fermez le navigateur, et vous pourrez continuer là où vous vous étiez arrêté.",
					acceptAllBtn: "Accepter les cookies fonctionnels",
					acceptNecessaryBtn: "Ne pas autoriser",
					showPreferencesBtn: "Gérer les paramètres",
					footer: "<a href=\"" + base_url + "privacy_policy\">Politique de confidentialité</a>\n<a href=\"" + base_url + "terms_of_service\">Termes et conditions</a>"
				},
				preferencesModal: {
					title: "Préférences de consentement",
					acceptAllBtn: "Tout accepter",
					acceptNecessaryBtn: "Tout rejeter",
					savePreferencesBtn: "Enregistrer les paramètres",
					closeIconLabel: "Fermer la boîte de dialogue",
					serviceCounterLabel: "Services",
					sections: [
						{
							title: "Utilisation des cookies",
							description: "Nous n\'utilisons pas de cookies de tiers pour la publicité ou l\'analyse. Si vous l\'autorisez, cet appareil sera reconnu même si vous fermez le navigateur, et vous pourrez continuer là où vous vous étiez arrêté. Sinon, seuls des cookies de session sont installés, qui sont automatiquement supprimés dès que vous fermez le navigateur (ou lorsque vous vous déconnectez)."
						},
						{
							title: "Cookies strictement nécessaires <span class=\"pm__badge\">Toujours activés</span>",
							description: "Sans cookies de session, il n\'est pas possible de sauvegarder la progression de votre étude.",
							linkedCategory: "necessary"
						},
						{
							title: "Cookies de fonctionnalité",
							description: "Ces cookies nous permettent de reconnaître à nouveau votre appareil. Ainsi, vous pouvez interrompre l\'étude et continuer plus tard là où vous vous étiez arrêté.",
							linkedCategory: "functionality"
						}
					]
				}
			}
		}
	}
});

});