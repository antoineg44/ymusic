(function () {
  // Gère l'authentification de session et le comportement du bouton de déconnexion.
  function createAuthController(deps) {
    const {
      state,
      manageUsersLink,
      logoutButton,
    } = deps;

    if (logoutButton) {
      logoutButton.addEventListener('click', () => {
        void logout();
      });
    }

    async function ensureAuthenticated() {
      // Vérifie la session côté serveur avant d'initialiser le reste de l'application.
      try {
        const response = await fetch(get_url_from_base() + 'php/auth.php?action=check', {
          cache: 'no-store',
        });
        const payload = await response.json();

        if (!payload.success) {
          window.postMessage({type: 'USER_LOGGED_OUT' }, '*');
          return false;
        }

        state.currentUser = payload.user || null;

        if (manageUsersLink && state.currentUser && state.currentUser.role !== 'admin') {
          manageUsersLink.style.display = 'none';
        }
        return true;
      } catch (error) {
        console.error(error);
        window.postMessage({type: 'USER_LOGGED_OUT' }, '*');
        return false;
      }
    }

    async function logout() {
      // Termine la session serveur puis redirige vers la page de connexion.
      try {
        await fetch(get_url_from_base() + 'php/auth.php?action=logout', {
          method: 'POST',
        });
      } catch (error) {
        console.error(error);
      } finally {
        window.postMessage({type: 'USER_LOGGED_OUT' }, '*');
      }
    }

    return {
      ensureAuthenticated,
      logout,
    };
  }

  window.createAuthController = createAuthController;
})();
