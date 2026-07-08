# CodeLand Quiz — Projektna specifikacija

## 1. Cilj sistema

CodeLand Quiz je real-time kviz platforma za izvođenje edukativnih kvizova u učionici ili online nastavi. Sistem omogućava nastavniku da kreira kviz, pokrene sesiju, prikaže pitanja učenicima u realnom vremenu i prati rezultate tokom igre.

## 2. Korisničke uloge

### ADMIN

Admin može:

- prijaviti se u sistem;
- dodavati druge admine;
- dodavati nastavnike;
- deaktivirati korisnike;
- kreirati, uređivati i brisati sve kvizove;
- dodavati učenike;
- pokretati kviz sesije;
- pregledati istoriju i statistiku.

### TEACHER

Nastavnik može:

- prijaviti se u sistem;
- kreirati, uređivati i brisati kvizove;
- dodavati učenike;
- pokretati kviz sesije;
- pregledati rezultate i statistiku.

### STUDENT

Učenik nema klasičan login. Učenik može učestvovati u kvizu samo ako postoji u sistemu i ima dodijeljen username.

Učenik se pridružuje kvizu pomoću:

- Game PIN-a ili QR koda;
- svog username-a;
- nickname-a za konkretnu igru;
- izabranog Kode avatara.

## 3. Glavne funkcionalnosti

### Upravljanje korisnicima

- admin može dodavati nove admine;
- admin može dodavati nastavnike;
- admin/nastavnik može dodavati učenike;
- učenici imaju username;
- samo registrovani učenici mogu igrati kviz.

### Upravljanje kvizovima

- prikaz liste svih kvizova;
- kreiranje novog kviza;
- izmjena kviza;
- brisanje kviza;
- dodavanje pitanja;
- dodavanje slike uz pitanje;
- definisanje vremena za odgovor;
- definisanje broja bodova;
- definisanje tipa pitanja.

### Tipovi pitanja

Sistem podržava:

- tačno/netačno;
- jedan tačan odgovor;
- više tačnih odgovora.

Kod ponuđenih odgovora koriste se četiri opcije.

### Pokretanje igre

Nastavnik:

- bira kviz;
- kreira novu sesiju;
- dobija Game PIN;
- dobija QR kod;
- čeka da se učenici pridruže;
- ručno pokreće kviz;
- ručno prelazi na sljedeće pitanje.

### Tok igre

1. Nastavnik pokreće sesiju.
2. Učenici se pridružuju preko PIN-a ili QR koda.
3. Učenik unosi username.
4. Učenik bira nickname i Kode avatar.
5. Nastavnik pokreće pitanje.
6. Pitanje se prikazuje svim učenicima istovremeno.
7. Učenici odgovaraju u zadatom vremenu.
8. Server računa tačnost, vrijeme i bodove.
9. Nastavnik vidi statistiku uživo.
10. Nakon pitanja prikazuje se rang-lista.
11. Nastavnik prelazi na sljedeće pitanje.
12. Na kraju se prikazuje konačan poredak.

## 4. Bodovanje

Bodovanje zavisi od:

- tačnosti odgovora;
- brzine odgovora;
- maksimalnog broja bodova za pitanje.

Netačan odgovor nosi 0 bodova.

Tačan odgovor dobija bodove prema preostalom vremenu.

## 5. Real-time statistika

Tokom pitanja nastavnik vidi:

- broj prijavljenih učenika;
- broj učenika koji su odgovorili;
- broj učenika koji nisu odgovorili;
- preostalo vrijeme;
- najbrži odgovor;
- prosječno vrijeme odgovora;
- raspodjelu odgovora po opcijama;
- rang-listu nakon pitanja.

## 6. Istorija i statistika

Nakon završetka kviza nastavnik vidi:

- ko je učestvovao;
- konačan broj bodova;
- plasman;
- broj tačnih odgovora po učeniku;
- broj netačnih odgovora po učeniku;
- prosječno vrijeme odgovora;
- odgovore po pitanju;
- procenat tačnih odgovora po pitanju;
- najteže pitanje.

## 7. Responzivnost

Učenički dio aplikacije mora biti mobile-first.

To znači:

- prilagođen telefonima;
- velika dugmad za odgovore;
- jasno vidljiv tajmer;
- jednostavan prikaz pitanja;
- slika prilagođena širini ekrana;
- minimalan broj klikova;
- prikaz prilagođen djeci.

Profesorski dio je desktop-first, jer nastavnik najčešće koristi laptop ili računar.

## 8. Tehnologije

Backend:

- PHP 8.3;
- OpenSwoole;
- Composer;
- MySQL.

Frontend:

- Angular;
- TypeScript;
- responsive UI.

Infrastruktura:

- Docker;
- phpMyAdmin;
- GitHub.

Sigurnost:

- HTTPS;
- hashovanje lozinki;
- JWT autentikacija;
- validacija podataka;
- CORS konfiguracija;
- zaštita upload-a slika;
- `.env` fajl van GitHub-a.

## 9. Van opsega prve verzije

U prvoj verziji ne radimo:

- video pozive;
- Scratch editor;
- AI analizu časa;
- roditeljski portal;
- plaćanja;
- email notifikacije;
- mobilnu aplikaciju.
  