# Tokovi korisnika

> Status: Implemented

Ovaj dokument opisuje implementirani backend. Angular korisničko sučelje je planirano.

## Administrator

1. Prijavljuje se e-mail adresom i lozinkom. Ako je `mustChangePassword` postavljen, prvo mijenja lozinku.
2. Upravlja nastavničkim računima: kreiranje, pregled, izmjena, aktivacija/deaktivacija i reset privremene lozinke. Backend nema rutu za kreiranje drugih administratora.
3. Upravlja učenicima, zajedničkim temama, kvizovima i pitanjima.
4. Aktivira kviz, kreira sesiju i ručno vodi njen životni ciklus.
5. Prati učesnike REST endpointom, uklanja učesnika te pregleda historiju, izvještaje i statistike.

## Nastavnik

Nastavnik koristi isti staff login i obaveznu promjenu lozinke. Može upravljati učenicima, temama, kvizovima i pitanjima, kreirati i ručno voditi sesije, upravljati učesnicima te pregledati historiju i statistike. Ne može koristiti administratorske rute za nastavničke račune.

## Registrovani učesnik

1. Poziva `GET /api/game/session/{gamePin}` za javni pregled sesije.
2. Poziva `POST /api/game/join` sa tipom `REGISTERED`, korisničkim imenom učenika, nadimkom i avatarom.
3. Dobija participant JWT i otvara `/ws/game`.
4. Nakon `AUTHENTICATION_REQUIRED` šalje `PARTICIPANT_AUTHENTICATE`.
5. Prima trenutno stanje, a odgovor šalje porukom `ANSWER_SUBMIT`.
6. `ANSWER_ACCEPTED` potvrđuje prijem bez otkrivanja tačnosti. Nakon nastavnikovog zatvaranja pitanja stižu zajednički rezultat pitanja, personalizirani rezultat i tabela poretka.
7. Ponovno povezivanje je podržano u stanjima WAITING, ACTIVE sa otvorenim ili zatvorenim pitanjem, i FINISHED.

## Gost

Tok je isti, ali je `participantType` vrijednosti `GUEST`, ne šalje se identitet iz registra učenika i ne kreira se red u tabeli `students`. Gost bira nadimak i avatar samo za konkretnu sesiju.

## Tok sesije

Sve nastavničke operacije su REST: kreiranje sesije, start, zatvaranje trenutnog pitanja, pokretanje sljedećeg pitanja, završetak i uklanjanje učesnika. Sve tranzicije su ručne; nema automatskog timera koji mijenja stanje. Učesničke real-time operacije koriste WebSocket.

Nastavnički live pregled učesnika je `GET /api/sessions/{id}/participants`, ne nastavnički WebSocket. Backend ne generiše QR sliku; frontend je kasnije može napraviti kodiranjem PIN-a ili join URL-a.
