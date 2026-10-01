import axios, { type AxiosError } from 'axios';

// URL de l'API Laravel : définie dans .env (VITE_API_URL), voir .env.example
const API_BASE_URL: string =
  import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8001/api';

export const TOKEN_KEY = 'ticketlab_token';

/** Événement émis quand l'API répond 401 sur une route protégée. */
export const UNAUTHORIZED_EVENT = 'ticketlab:unauthorized';

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    Accept: 'application/json',
  },
});

// Ajoute le token d'authentification
apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY);

  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  return config;
});

// Session expirée : on nettoie et on prévient l'application. On ne recharge
// JAMAIS la page (sinon le message d'erreur du login ne s'affiche pas), et un
// 401 sur /login signifie simplement "identifiants invalides".
apiClient.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    const isLoginCall = error.config?.url === '/login';

    if (error.response?.status === 401 && !isLoginCall) {
      localStorage.removeItem(TOKEN_KEY);
      window.dispatchEvent(new Event(UNAUTHORIZED_EVENT));
    }

    return Promise.reject(error);
  },
);

/**
 * Extrait un message lisible d'une erreur Axios. Gère le cas des réponses
 * `responseType: 'blob'` où le JSON d'erreur arrive sous forme de Blob.
 */
export async function getErrorMessage(
  error: unknown,
  fallback = 'Une erreur est survenue.',
): Promise<string> {
  const axiosError = error as AxiosError<unknown>;

  if (!axiosError?.isAxiosError) {
    return fallback;
  }

  // Pas de réponse HTTP du tout. Deux causes très différentes se confondent ici :
  // le serveur est vraiment injoignable, OU l'upload a été tronqué par PHP avant
  // d'atteindre Laravel (post_max_size / upload_max_filesize). Dans le second
  // cas, le message « vérifiez votre connexion » envoie l'utilisateur dans la
  // mauvaise direction alors que son fichier est la cause : on distingue les deux.
  if (!axiosError.response) {
    const aborted = axiosError.code === 'ERR_CANCELED';

    if (aborted) {
      return 'Génération annulée.';
    }

    // ERR_FAILED sur un POST multipart : corps de requête rejeté avant PHP.
    // Une réseau coupé donne généralement ERR_NETWORK.
    if (axiosError.code === 'ERR_BAD_REQUEST' || axiosError.code === 'ERR_FAILED') {
      return "Le serveur a refusé la requête sans répondre. Causes fréquentes : le fichier envoyé dépasse la taille maximale acceptée par le serveur (ZIP de QR codes ou template), ou le serveur est indisponible.";
    }

    return 'Impossible de joindre le serveur. Vérifiez que le backend est démarré sur le port défini dans VITE_API_URL.';
  }

  let data: unknown = axiosError.response.data;

  if (data instanceof Blob) {
    try {
      data = JSON.parse(await data.text());
    } catch {
      data = null;
    }
  }

  const payload = data as { message?: string } | null;

  if (payload?.message) {
    return payload.message;
  }

  if (axiosError.response.status === 413) {
    return 'Les fichiers envoyés sont trop volumineux pour le serveur.';
  }

  return fallback;
}

export default apiClient;
