#!/usr/bin/env python3
"""
Génère les "fonds nettoyés" des templates TicketLab.

Les visuels d'origine (template/Stand.jpg, template/Lavage.jpg) contiennent déjà
leurs textes et un QR d'exemple. Le backend redessine les textes éditables par
dessus : il faut donc retirer les textes d'origine, sinon l'ancien texte
apparaît sous le nouveau.

Méthode (mieux qu'un simple rectangle de couleur) :
  - mode "fill"  : aplat de couleur (médiane du pourtour) pour les blocs unis
  - mode "text"  : masque précis des traits du texte (écart avec une estimation
                   lisse du fond) puis inpainting : préserve les formes
                   diagonales du fond
  - mode "grad"  : interpolation des quatre bords (patch de Coons) pour les
                   dégradés, sans trace visible
  - mode "white" : blanc pur (zone du QR d'exemple)

Usage : python3 tools/make_clean_plate.py
Dépendances : pip install opencv-python-headless numpy
"""
import os
import sys
import cv2
import numpy as np

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "back", "resources", "templates", "images")

S = 2362 / 1092  # les repères du Lavage ont été relevés sur une vue 1092 px

def sc(*v):
    return [round(i * S) for i in v]

TEMPLATES = {
    "ticket_stand_v1.jpg": {
        "src": "template/Stand.jpg",
        "rects": [
            # (x0, y0, x1, y1, mode)
            (80, 108, 395, 170, "text"),     # titre
            (836, 112, 908, 166, "text"),    # numéro
            (205, 270, 488, 320, "grad"),    # montant (bloc jaune)
            (113, 340, 480, 410, "text"),    # description
            (172, 430, 455, 466, "text"),    # téléphone
            (140, 490, 470, 545, "grad"),    # note (bloc vert)
            (605, 246, 913, 554, "white"),   # QR d'exemple
        ],
    },
    "ticket_parking_v1.jpg": {
        # Visuel Ticketche fourni (4188 x 2713) ramené à 2048 x 1326 :
        # partagé par les parcours Parking ET Lavage (cf. TemplateSeeder).
        "src": "template/parking.jpeg",
        "size": (2048, 1326),
        "rects": [
            (1602, 250, 1720, 400, "text"),    # chiffre "1" (on garde "N°")
            (1085, 548, 1990, 678, "fill"),    # "SERVICE TICKETCHE" (bandeau jaune uni)
            (1015, 698, 1490, 760, "text"),    # "Solution de stationnement"
            (1512, 712, 1760, 764, "fill"),    # "7j/7 - 24h/24" (pastille brune unie)
            (1148, 858, 1550, 930, "text"),    # téléphone
            (1148, 775, 1770, 1105, "text"),   # adresse (3 lignes)
            # QR d'exemple : on blanchit l'intérieur du cadre arrondi mais on
            # garde ses bords (le cadre blanc arrondi fait partie du visuel).
            (200, 407, 940, 1149, "white"),
        ],
    },
    "ticket_lavage_v1.jpg": {
        "src": "template/Lavage.jpg",
        "rects": [
            (*sc(198, 132, 892, 176), "grad"),   # titre (pilule jaune)
            (*sc(215, 205, 890, 285), "text"),   # accroche
            (*sc(125, 405, 458, 485), "grad"),   # titre du panneau
            (*sc(128, 522, 508, 650), "grad"),   # promo (bloc jaune)
            (*sc(318, 692, 475, 756), "grad"),   # "Téléchargez..."
            (*sc(340, 782, 510, 850), "grad"),   # puces (bloc jaune)
            (*sc(130, 930, 500, 962), "grad"),   # libellé parrainage
            (*sc(85, 980, 560, 1030), "grad"),   # code parrainage
            (*sc(685, 965, 1012, 1030), "grad"), # aide + téléphone
            (*sc(120, 684, 290, 852), "white"),  # QR d'exemple
        ],
    },
}


def ring_median(img, x0, y0, x1, y1, pad=3):
    h, w = img.shape[:2]
    parts = [
        img[max(y0 - pad, 0):y0, x0:x1].reshape(-1, 3),
        img[y1:min(y1 + pad, h), x0:x1].reshape(-1, 3),
        img[y0:y1, max(x0 - pad, 0):x0].reshape(-1, 3),
        img[y0:y1, x1:min(x1 + pad, w)].reshape(-1, 3),
    ]
    return np.median(np.vstack([p for p in parts if len(p)]), axis=0)


def coons(img, x0, y0, x1, y1, pad=3):
    """Interpole l'intérieur du rectangle à partir des 4 bords."""
    h, w = y1 - y0, x1 - x0
    src = img.astype(np.float32)
    top = src[y0 - pad:y0 - 1, x0:x1].mean(axis=0)      # (w,3)
    bottom = src[y1 + 1:y1 + pad, x0:x1].mean(axis=0)
    left = src[y0:y1, x0 - pad:x0 - 1].mean(axis=1)     # (h,3)
    right = src[y0:y1, x1 + 1:x1 + pad].mean(axis=1)
    u = np.linspace(0, 1, w)[None, :, None]
    v = np.linspace(0, 1, h)[:, None, None]
    horiz = (1 - u) * left[:, None, :] + u * right[:, None, :]
    vert = (1 - v) * top[None, :, :] + v * bottom[None, :, :]
    c00, c10 = top[0], top[-1]
    c01, c11 = bottom[0], bottom[-1]
    bil = ((1 - u) * (1 - v) * c00 + u * (1 - v) * c10
           + (1 - u) * v * c01 + u * v * c11)
    return np.clip(horiz + vert - bil, 0, 255)


def main():
    # Usage : python3 tools/make_clean_plate.py [ticket_parking_v1.jpg ...]
    # Sans argument, tous les fonds sont regénérés.
    only = sys.argv[1:]
    for out_name, cfg in TEMPLATES.items():
        if only and out_name not in only:
            continue
        img = cv2.imread(os.path.join(ROOT, cfg["src"]))
        if img is None:
            raise SystemExit(f"Image introuvable : {cfg['src']}")
        if cfg.get("size"):
            img = cv2.resize(img, cfg["size"], interpolation=cv2.INTER_AREA)
        ref = img.copy()  # on lit toujours les bords dans l'original
        for x0, y0, x1, y1, mode in cfg["rects"]:
            if mode == "white":
                img[y0:y1, x0:x1] = 255
            elif mode == "fill":
                img[y0:y1, x0:x1] = ring_median(ref, x0, y0, x1, y1)
            elif mode == "text":
                est = coons(ref, x0, y0, x1, y1)
                diff = np.abs(ref[y0:y1, x0:x1].astype(np.float32) - est).max(axis=2)
                mask = np.zeros(img.shape[:2], np.uint8)
                mask[y0:y1, x0:x1] = (diff > 30).astype(np.uint8) * 255
                mask = cv2.dilate(mask, np.ones((7, 7), np.uint8))
                img = cv2.inpaint(img, mask, 5, cv2.INPAINT_TELEA)
            else:
                img[y0:y1, x0:x1] = coons(ref, x0, y0, x1, y1).astype(np.uint8)
        os.makedirs(OUT, exist_ok=True)
        dest = os.path.join(OUT, out_name)
        cv2.imwrite(dest, img, [cv2.IMWRITE_JPEG_QUALITY, 95])
        print("OK", dest, img.shape[1], "x", img.shape[0])


if __name__ == "__main__":
    main()
