# Verfahren Bi-Temporal mit Beispielen und Grafiken
Das Beispiel in /docs/bitemporal.md ist falsch. Mit einer Terminierung sowohl auf der Achse valid time (vt) als auch der Achse transaction time (tt) führt zu lücken. Das heisst, wenn man ein Query rückwirkend auf der Achse tt sucht, dann wird der Zustand von damals nicht mehr gefunden. Da bi-temporal auf zwei Achsen eine Fläche darstellt, möchte ich die Beispiele als Flächen darstellen. Dashalb habe ich mir drei Beispiele als Grafiken gezeichnet. Die fetten schwarzen Linien sind jeweils Abterminierungen, d.h. dort wird ein Datum (meist 9999-12-31) auf jetzt minus 1 Sekunde gesetzt.

## Ich beschreibe nun drei Beispiele:

[Grafik 1:](./bi-temp_1_normale_update.png) 
Sie zeigt was bei einem ganz normalen Update passiert. Im Beispiel ist eine normale Adresse erfasst (v-from: 01.04.2024 bis unendlich). Sie wurde rückwirkend erfasst, nämlich am 01.06.2024 (tt) bis unendlich.

Der Kunde zieht am 15.07.2024 an die Maierstrasse 2 um als neue Adresse. Am selben Tag erfassen wir diese Information. Nun passiert folgendes:
1. Es wird eine Kopie des originalen Records Rec 1 eingefügt mit Vt-until: jetzt - 1 sec oder bei nur Datum (heute - 1 Tag) -> Rec 1'
2. Der bisherige Record (Rec 1) wird auf der Achse TT abterminiert, d.h. tt-until: jetzt - 1 sec.
3. Der Record mit der neuen Information wird eingefügt vt-from: nach Eingabe, tt-from: jetzt

[Grafik 2](./bi-temp_2_correction.png):
Wir bemerken, dass die Hausnummer falsch erfasst wurde. Anstatt Nummer 2 hätte es die Nummer 4 sein sollen. Der Umzug hat aber trotzdem am 15.07.2024 stattgefunden. Bei einer unitemporalen Tabelle würden wir einfach mutieren und wir wüssten nicht per wann der Umzuge gewesen ist. Der User ändert einfach die Hausnummer auf dem Formular. Die Magie passiert im Hintergrund. Also macht das System eine Korrektur wie folgt:
1. Das System erkennt, dass vt-from identisch bleibt und es somit keinen Platz für Rec 2' hat. Dieser wird nicht angelegt.
2. Der bisherige Rec 2 wird analog Grafik 1 auf der Achse tt abterminiert, d.h. tt-until: jetzt - 1 sec.
3. Der korrigierte Record (Rec 3) wird mit derselben vt-from und vt-until eingefügt. tt-from: jetzt und tt-until: unendlich

[Grafik 3:](./bi-temp_3_correction_between.png) 
Jetzt wird es komplex. Im Bespiel geht es um Preise. Als Ausgangslage haben wir 3 Preise erfasst, wobei Rec 3 auf der vt Achse in der Zukunft liegt. Rec 3 wurde am 01.08.2024 erfasst mit Wirkung 01.09.2024 (vt). Am 13.08.2024 kommt Sales auf uns zu uns sagt, dass wir für die Zeit vom 15.08.2024 bis 14.09.2024 (inklusive) eine Discount anbieten sollen, um das Sommergeschäft anzukurbeln. Ab 15.09.2024 gilt wieder der abgemachte Preis vom 01.09.2024.

Für uns heisst das, dass wir einen Preis auf der vt Achse dazwischen schieben müssen. Der User mutiert sein Form auf dem Display wie folgt: neuer Preis ab: 15.08.2025, Preis bis: 14.08.2024 (inklusive). Was passiert im Hintergrund:
1. Wir suchen alle Records, welche eine Überlappung mit dem neuen Record haben. Theoretisch könnten andere Records ganz überschrieben werden. Hier werden Rec 2' und Rec 3 gefunden.
2a. Jetzt passiert derselbe Schritt wie bei den Grafiken 1 und 2. Rec 2'' wird kopiert von Rec 2' mit vt-until: neues vt-from - 1 Tag (hier)
2b. Diser Schritt passiert nur, wenn nach unserem neuen vt-until bereits eine Überlappung existiert, d.h. Rec 3 wird kopiert mit dem neuen vt-from: vt-until neuer Record + 1 Tag
3. Alle in Schritt 1 gefundenen Records (hier Rec 2' und Rec 3) werden auf der Achse tt abterminiert, d.h. tt-until: jetzt - 1 sec.
4. Rec 4 wird eingefügt mit vt-from: Eingabe 15.08.2024, vt-until: Eingabe 14.08.2024, tt-from: jetzt, tt-until: unendlich

## Generell:
Es diesen Beispielen lässt sich ein generelles Vorgehen ableiten. Die Schritte von Grafik 3 müssten auch für die einfachen Fälle funktionieren.

