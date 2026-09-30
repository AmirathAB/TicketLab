# TicketLab — Frontend (React + Vite + TypeScript)

Interface de TicketLab : connexion, choix du support et du secteur, template
personnel (avec éditeur de zone QR) ou template TicketLab (texte modifiable en
direct), puis génération et téléchargement du ZIP.

## Installation

```bash
cd front
npm install
cp .env.example .env     # puis adapter VITE_API_URL
```

`.env` :

```
VITE_API_URL=http://127.0.0.1:8001/api
```

Le backend doit autoriser l'origine du front (`FRONTEND_URL` dans `back/.env`).

## Développement

```bash
npm run dev      # http://localhost:5173
npm run lint
```

## Build de production

```bash
npm run build    # sortie dans dist/ (hébergeable sur Vercel, Netlify, serveur statique)
```

`VITE_API_URL` est lue **au build** : définissez-la avant `npm run build`.

## Structure

```
src/
  api/client.ts                  axios + token + gestion du 401 et des erreurs blob
  components/editor/QrZoneEditor.tsx   zone QR : déplacer, redimensionner, dessiner
  types/ticketlab.ts             types partagés
  utils/qrZone.ts                bornage de la zone dans l'image
  assets/fonts/                  Poppins (mêmes .ttf que le backend)
  App.tsx                        wizard complet
```

## Points d'attention

- Toutes les coordonnées (champs, zone QR) sont en **pixels natifs de l'image**,
  jamais en pixels d'écran. L'aperçu les convertit en pourcentages, et la taille
  du texte en `cqw` (unité de conteneur) pour ne pas dépendre de la fenêtre.
- L'aperçu et le rendu serveur utilisent la même police (Poppins) mais pas le même
  moteur : la césure d'une ligne longue peut différer de quelques pixels.
  Pour un rendu identique, préférez des retours à la ligne explicites dans les
  champs multi-lignes.
- Le jeton est stocké dans `localStorage` ; la session est revérifiée via
  `/api/me` au chargement.
