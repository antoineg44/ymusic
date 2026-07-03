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
        const response = await fetch('php/auth.php?action=check', {
          credentials: 'same-origin',
          cache: 'no-store',
        });
        const payload = await response.json();

        if (!payload.success) {
          window.location.replace('login.html');
          return false;
        }

        state.currentUser = payload.user || null;

        if (manageUsersLink && state.currentUser && state.currentUser.role !== 'admin') {
          manageUsersLink.style.display = 'none';
        }
        return true;
      } catch (error) {
        console.error(error);
        window.location.replace('login.html');
        return false;
      }
    }

    async function logout() {
      // Termine la session serveur puis redirige vers la page de connexion.
      try {
        await fetch('php/auth.php?action=logout', {
          method: 'POST',
          credentials: 'same-origin',
        });
      } catch (error) {
        console.error(error);
      } finally {
        window.location.replace('login.html');
      }
    }

    return {
      ensureAuthenticated,
      logout,
    };
  }

  window.createAuthController = createAuthController;
})();
