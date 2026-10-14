(function () {
  // Gère l'authentification de session et le comportement du bouton de déconnexion.
  function createAuthController(deps) {
    const {
      state,
      logoutButton,
    } = deps;

    if (logoutButton) {
      logoutButton.addEventListener('click', () => {
        void logout();
      });
    }

    async function ensureAuthenticated() {
      // Vérifie la session côté serveur avant d'initialiser le reste de l'application.
      sendMessageAndWait(window.parent, {action: "check"}).then(response => {
        const payload = response;
        if (!payload.success) {
          window.postMessage({type: 'USER_LOGGED_OUT' }, '*');
          openLoginModal();
          return;
        }
        state.currentUser = payload.user || null;
      }).catch(error => {
          console.error(error);
          openLoginModal();
      });
    }

    async function logout() {
      // Termine la session serveur puis reaffiche la modale de connexion.
      sendMessageAndWait(window.parent, {action: "logout"}).then(response => {
        window.postMessage({type: 'USER_LOGGED_OUT' }, '*');
        openLoginModal();
      }).catch(error => {
          console.error(error);
      });
    }

    return {
      ensureAuthenticated,
      logout,
    };
  }

  window.createAuthController = createAuthController;
})();
