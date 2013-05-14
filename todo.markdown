# Zentrale Todo

* LOOPS
szenario: wartezeit von 4 wochen nach trainingsbeginn. dann branch: wenn 8 mal ausgefüllt: wave 2. wenn nicht: warten bis 8 mal ausgefüllt. erfordert neu-machen der session-logik
1 pause 4 weeks
2 branch: 8 mal training -> wave 2 (5), else training (3)
3 training
4 loop (if training > 8) -> wave 2 (5) else training (3)
5 wave 2

* validate ALL html.
* cron jobs

### what to do when rolling out
* htaccess config pruefen, often problems with RewriteBase
* ist results_backups writable?
* define_root has a hardcoded path atm.
