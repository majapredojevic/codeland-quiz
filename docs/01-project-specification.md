# Specifikacija projekta CodeLand Quiz

> Status: Implemented

## Svrha i korisnici

CodeLand Quiz je web platforma za kreiranje i ručno vođenje kvizova u učionici. Staff korisnici su `ADMIN` i `TEACHER`; imaju e-mail, hash lozinke i JWT autentikaciju. Učenici su poseban registar bez e-maila, lozinke ili staff JWT prijave. U sesiji učestvuju registrovani učenici (`REGISTERED`) ili gosti (`GUEST`).

Administrator upravlja nastavničkim računima. Administrator i nastavnik upravljaju učenicima, zajedničkim temama, kvizovima, pitanjima i sesijama te pregledaju rezultate i statistike.

## Kvizovi i pitanja

Kviz pripada opcionalnoj temi, ima naslov, verziju, opis, status aktivnosti i soft-delete životni ciklus. Aktivni kviz se može snimiti u novu sesiju.

Pravila pitanja:

- `TRUE_FALSE`: tačno dvije opcije redom „Tačno“ i „Netačno“; tačno jedna je ispravna.
- `SINGLE_CHOICE`: dvije ili četiri opcije; tačno jedna je ispravna.
- `MULTIPLE_CHOICE`: četiri opcije; dvije ili tri su ispravne.
- Vrijeme odgovora je 30–300 sekundi, a `maxPoints` 1–10000.

Slika pitanja se učitava kroz zaštićeni staff endpoint, sprema pod generisanim imenom u upravljanu putanju i poslužuje kroz sigurni `/media/question-images/...` endpoint.

## Sesija i snapshot

Kreiranje sesije kopira naslov/verziju kviza, pitanja i opcije u snapshot tabele. Kasnije izmjene ili brisanje izvornog sadržaja zato ne mijenjaju historijski tok i izvještaj sesije. Statusi su `WAITING`, `ACTIVE` i `FINISHED`.

Nastavnik ručno pokreće sesiju, zatvara trenutno pitanje, pokreće sljedeće i završava sesiju. Nema automatskih prijelaza ni automatskog završetka.

## Bodovanje

Netačan odgovor donosi 0 bodova. Za tačan odgovor:

```text
remainingRatio = remaining time / time limit
multiplier = 0.5 + 0.5 * remainingRatio
points = round(maxPoints * multiplier)
```

Tačan odgovor donosi približno 100% bodova na početku, 75% na polovini i 50% na roku. Bodovanje koristi vrijeme odgovora ograničeno na interval pitanja.

## Izvještaji

Implementirani su historija sesija, završni izvještaj sesije, agregatna statistika kviza i dugoročna statistika registrovanog učenika. Live distribucija opcija i najbrži odgovor nisu zaseban nastavnički WebSocket proizvod.

## Granice trenutne verzije

Backend ne generiše QR slike; Angular lobby generiše QR kod iz join URL-a. Nisu implementirani posebni razvojni režimi, AI mogućnosti, e-mail obavijesti, CAPTCHA ni automatsko vođenje pitanja.
