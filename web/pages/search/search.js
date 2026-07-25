let suggestionTimer = null;
let suggestionRequestInFlight = false;
let queuedSuggestionQuery = null;
const MIN_INPUT_WIDTH_WITH_LABEL = 220;
const PRIORITY_RESULTS_COUNT = 3;
const DEFERRED_RESULTS_COUNT = 17;
const DEFERRED_RENDER_DELAY_MS = 450;

const searchRow = document.querySelector(".search-row");
const searchEmbed = document.querySelector(".search-embed");
const searchInput = document.getElementById("searchInput");
const searchButton = document.getElementById("searchButton");
const suggestionsBox = document.getElementById("suggestions");
const suggestionsSpinner = document.getElementById("suggestionsSpinner");
const statusBox = document.getElementById("searchStatus");
const searchSpinner = document.getElementById("searchSpinner");
const searchResults = document.getElementById("searchResults");
let lastSentHeight = 0;
let currentSearchToken = 0;
let deferredRenderTimerId = null;

function postToParent(type, payload = {}) {
  window.parent.postMessage({ source: "recherche", type, ...payload }, "*");
}

function updateSearchButtonLayout() {
  if (!searchRow || !searchButton) {
    return;
  }

  searchButton.classList.remove("is-icon-only");
  searchButton.removeAttribute("aria-label");

  const rowStyle = window.getComputedStyle(searchRow);
  const gapValue = rowStyle.columnGap || rowStyle.gap || "10px";
  const gap = Number.parseFloat(gapValue) || 10;
  const requiredWidth =
    searchButton.offsetWidth + MIN_INPUT_WIDTH_WITH_LABEL + gap;
  const shouldUseIconOnly = searchRow.clientWidth < requiredWidth;

  searchButton.classList.toggle("is-icon-only", shouldUseIconOnly);
  if (shouldUseIconOnly) {
    searchButton.setAttribute("aria-label", "Rechercher");
  }
}

function notifyParentHeight() {
  window.requestAnimationFrame(() => {
    const embedHeight =
      searchEmbed instanceof HTMLElement ? searchEmbed.scrollHeight : 0;
    const bodyHeight = document.body ? document.body.scrollHeight : 0;
    const height = Math.max(embedHeight, bodyHeight, 1);

    if (height !== lastSentHeight) {
      lastSentHeight = height;
      postToParent("SEARCH_RESIZE", { height });
    }
  });
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/\"/g, "&quot;");
}

function setStatus(message) {
  statusBox.textContent = message;
  //statusBox.style.color = "#FF0000";
  notifyParentHeight();
}

function showSuggestionsSpinner() {
  suggestionsSpinner.classList.add("visible");
  notifyParentHeight();
}

function hideSuggestionsSpinner() {
  suggestionsSpinner.classList.remove("visible");
  notifyParentHeight();
}

function showSearchSpinner() {
  searchSpinner.classList.add("visible");
  notifyParentHeight();
}

function hideSearchSpinner() {
  searchSpinner.classList.remove("visible");
  notifyParentHeight();
}

async function requestSuggestions(query) {
  try {
    showSuggestionsSpinner();
    const response = await fetch(
      `search.php?suggestions=${encodeURIComponent(query)}`,
    );
    const payload = await response.json();
    if (query === searchInput.value.trim()) {
      renderSuggestions(payload.suggestions || []);
    }
  } catch (error) {
    console.error(error);
  } finally {
    hideSuggestionsSpinner();
  }
}

async function processSuggestionQueue() {
  if (suggestionRequestInFlight) {
    return;
  }

  while (queuedSuggestionQuery !== null) {
    const query = queuedSuggestionQuery;
    queuedSuggestionQuery = null;

    if (!query) {
      renderSuggestions([]);
      continue;
    }

    suggestionRequestInFlight = true;
    try {
      await requestSuggestions(query);
    } finally {
      suggestionRequestInFlight = false;
    }
  }
}

function enqueueSuggestions(query) {
  queuedSuggestionQuery = query;
  void processSuggestionQueue();
}

async function searchMusic() {
  const query = searchInput.value.trim();

  if (!query) {
    setStatus("Saisissez un terme de recherche.");
    return;
  }

  currentSearchToken += 1;
  const searchToken = currentSearchToken;

  if (deferredRenderTimerId !== null) {
    window.clearTimeout(deferredRenderTimerId);
    deferredRenderTimerId = null;
  }

  setStatus(`Recherche de "${query}"...`);
  suggestionsBox.innerHTML = "";
  showSearchSpinner();

  try {
    const response = await fetch(
      `search.php?query=${encodeURIComponent(query)}`,
    );
    const payload = await response.json();

    if (!payload.success) {
      setStatus(payload.error || "Recherche impossible.");
      searchResults.innerHTML = "<li>Aucun resultat disponible.</li>";
      return;
    }

    const results = payload.results || [];
    await renderSearchResults(results, searchToken);
  } catch (error) {
    console.error(error);
    setStatus("La recherche YouTube Music a echoue.");
  } finally {
    hideSearchSpinner();
  }
}

function renderSuggestions(suggestions) {
  if (!suggestions.length) {
    suggestionsBox.innerHTML = "";
    notifyParentHeight();
    return;
  }

  suggestionsBox.innerHTML = "";
  suggestions.forEach((suggestion) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "suggestion-chip";
    button.textContent = suggestion;
    button.addEventListener("click", () => {
      searchInput.value = suggestion;
      searchMusic();
    });
    suggestionsBox.appendChild(button);
  });
  notifyParentHeight();
}

function createRenderedItems(resultsBatch, startIndex) {
  const renderedItems = [];

  resultsBatch.forEach((result, offset) => {
    const index = startIndex + offset;
    const preparedResult = {
      ...result,
      title: result.title || "Titre inconnu",
      artists: Array.isArray(result.artists) ? result.artists : [],
      duration: result.duration || 0,
      views: result.views || 0,
      showIndex: false,
      buttons: {
        buttons: {
          play: {
            type: "SEARCH_PLAY_RESULT",
            result,
            className: "queue-action",
          },
        },
        source: "recherche",
      },
    };

    const item = renderElement(preparedResult, index);
    searchResults.appendChild(item);
    renderedItems.push({ item, result: preparedResult });
  });

  notifyParentHeight();
  return renderedItems;
}

async function annotateRenderedItems(renderedItems, searchToken) {
  const checks = [];

  for (const rendered of renderedItems) {
    if (searchToken !== currentSearchToken) {
      return checks;
    }

    const inDatabase = rendered.result['inDatabase'];
    if (searchToken !== currentSearchToken) {
      return checks;
    }

    rendered.item.classList.add(
      inDatabase ? "search-result-present" : "search-result-missing",
    );
    checks.push(inDatabase);
  }

  return checks;
}

async function renderSearchResults(results, searchToken) {
  if (!results.length) {
    searchResults.innerHTML = "<li>Aucun resultat trouve.</li>";
    notifyParentHeight();
    return;
  }

  searchResults.innerHTML = "";
  const limitedResults = results.slice(
    0,
    PRIORITY_RESULTS_COUNT + DEFERRED_RESULTS_COUNT,
  );
  const immediateResults = limitedResults.slice(0, PRIORITY_RESULTS_COUNT);
  const deferredResults = limitedResults.slice(PRIORITY_RESULTS_COUNT);

  const immediateRendered = createRenderedItems(immediateResults, 0);
  setStatus(
    `${limitedResults.length} resultat(s) trouve(s). Affichage prioritaire des ${immediateRendered.length} premiers...`,
  );

  const immediateChecks = await annotateRenderedItems(
    immediateRendered,
    searchToken,
  );
  if (searchToken !== currentSearchToken) {
    return;
  }

  let totalPresentCount = immediateChecks.filter(Boolean).length;

  if (!deferredResults.length) {
    setStatus(
      `${limitedResults.length} resultat(s) affiche(s). ${totalPresentCount} present(s) en base.`,
    );
    notifyParentHeight();
    return;
  }

  setStatus(
    `${immediateResults.length} resultat(s) affiche(s) en priorite. Chargement des ${deferredResults.length} suivants...`,
  );

  deferredRenderTimerId = window.setTimeout(() => {
    void (async () => {
      if (searchToken !== currentSearchToken) {
        return;
      }

      const deferredRendered = createRenderedItems(
        deferredResults,
        immediateResults.length,
      );
      const deferredChecks = await annotateRenderedItems(
        deferredRendered,
        searchToken,
      );
      if (searchToken !== currentSearchToken) {
        return;
      }

      totalPresentCount += deferredChecks.filter(Boolean).length;
      setStatus(
        `${limitedResults.length} resultat(s) affiche(s). ${totalPresentCount} present(s) en base.`,
      );
      notifyParentHeight();
    })();
  }, DEFERRED_RENDER_DELAY_MS);
}

searchButton.addEventListener("click", () => {
  void searchMusic();
});

searchInput.addEventListener("keydown", (event) => {
  if (event.key === "Enter") {
    void searchMusic();
  }
});

searchInput.addEventListener("input", (event) => {
  const query = event.target.value.trim();

  if (!query) {
    window.clearTimeout(suggestionTimer);
    queuedSuggestionQuery = null;
    suggestionsBox.innerHTML = "";
    notifyParentHeight();
    return;
  }

  window.clearTimeout(suggestionTimer);
  suggestionTimer = window.setTimeout(() => {
    enqueueSuggestions(query);
  }, 250);
});

postToParent("SEARCH_READY");
window.addEventListener("load", () => {
  notifyParentHeight();
  updateSearchButtonLayout();
});
window.addEventListener("resize", () => {
  notifyParentHeight();
  updateSearchButtonLayout();
});

if (window.ResizeObserver && searchRow) {
  const searchRowObserver = new ResizeObserver(() => {
    updateSearchButtonLayout();
  });
  searchRowObserver.observe(searchRow);
}
