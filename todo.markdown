# Zentrale Todo
Hier notiere ich einfach ein paar Sachen, die mir so auffallen, an Bugs, die mir jetzt nicht so dringlich erscheinen.

* validate html. im moment meckert w3c ziemlich
	* das wär unter umständen errreichbar, durch eine offensichtlichere include/require struktur. im moment hat man viele requires, die wiederum was requiren etc. unübersichtlich, dadurch schließen auf jeden fall manchmal tags nicht korrekt.
* session management verbessern. das ist zwar für manche nutzer nicht so ein problem, und die Id mit dem VpnCode funktioniert ja auch gut, sogar cross-browser und computer. aber dennoch wärs schön, wenn man eine seite auf der man grade ist, einfach mal neuladen könnte (z.B. wenn man sich auf sein homepageicon verklickt). grade beim entwickeln.
* get rid of eval, sowohl bei skipif als auch bei relevant support für and, or, klammern? ich werde da mal nach einem fertigscript suchen

# Mögliche Änderungen
Die kommen mir im Moment wie eine gute Idee vor.

* settings auch user editable machen. also alles was jetzt als konstante definiert wird.
* statt CSV JSON benutzen? gibt es vielleicht nicht so gute editoren für, wie für csv, vielleicht aber sogar bessere. dann hätte man das multiple choice problem nicht mehr und man könnte klarmachen, welche kolumnen wofür optional sind.
* eine Prise Ajax, z.B. bei den relevanten Fragen, für stufenlose Ratingskalen etc.
