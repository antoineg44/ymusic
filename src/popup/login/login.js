const form = document.getElementById("authForm");
const loginButton = document.getElementById("loginButton");
const loginSpinner = document.getElementById("loginSpinner");
const registerButton = document.getElementById("registerButton");
const message = document.getElementById("authMessage");

function setMessage(text, isError = true) {
  message.textContent = text;
  message.classList.toggle("is-error", isError);
  message.classList.toggle("is-success", !isError);
}

function lockButtons(locked) {
  loginButton.disabled = locked;
  registerButton.disabled = locked;
}

function setLoginLoading(isLoading) {
  loginSpinner.classList.toggle("is-hidden", !isLoading);
  loginButton.setAttribute("aria-busy", isLoading ? "true" : "false");
}

async function callAuth(action, body) {
  console.log("callAuth");
  try {
    const response = await sendMessageAndWait(window.parent, { action, body });
    console.log("callAuth response");
    const payload = response;

    if (!payload.success) {
      setMessage(payload.message || "Operation impossible.");
      return payload;
    }

    setMessage(
      action === "login"
        ? "Connexion reussie."
        : "Compte cree, connexion reussie.",
      false,
    );
    window.setTimeout(() => {
      window.parent.postMessage({ type: 'USER_LOGGED_IN' }, '*');
    }, 250);

    return payload;
  } catch (error) {
    console.error(error);
    throw error;
  }
}

async function submitAuth(action) {
  const username = String(
    document.getElementById("username").value || "",
  ).trim();
  const password = String(document.getElementById("password").value || "");

  if (!username || !password) {
    setMessage("Veuillez remplir les deux champs.");
    return;
  }

  setMessage("");
  lockButtons(true);
  setLoginLoading(action === "login");

  try {
    await callAuth(action, { username, password });
  } catch (error) {
    console.error(error);
    setMessage(
      error instanceof Error ? error.message : "Erreur reseau, reessayez.",
    );
  } finally {
    setLoginLoading(false);
    lockButtons(false);
  }
}

form.addEventListener("submit", (event) => {
  event.preventDefault();
  void submitAuth("login");
});

registerButton.addEventListener("click", () => {
  void submitAuth("register");
});

(async () => {
  sendMessageAndWait(window.parent, {action: "check"}).then(response => {
    const payload = response;
    if (payload.success) {
      window.parent.postMessage({type: 'USER_LOGGED_IN',}, '*');
    }
  }).catch(error => {
      console.error(error);
  });
})();
