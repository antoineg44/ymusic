class Console {
  constructor() {
    this.consoleDiv = document.createElement("div");
    this.consoleDiv.id = "__mini_console__";

    // Évite les doublons
    const ancien = document.getElementById("__mini_console__");

    if (ancien) {
      ancien.remove();
    }

    Object.assign(this.consoleDiv.style, {
      position: "fixed",
      bottom: "20px",
      left: "20px",
      width: "600px",
      height: "350px",
      background: "#111",
      color: "#eee",
      font: "14px monospace",
      zIndex: "999999",
      border: "1px solid #555",
      borderRadius: "8px",
      padding: "10px",
      boxSizing: "border-box",
      boxShadow: "0 5px 30px #000"
    });

    this.consoleDiv.innerHTML = `
      <div id="__output__"
          style="
            height:290px;
            overflow:auto;
            white-space:pre-wrap;
          ">
      </div>

      <div style="display:flex">
        <span style="margin-right:8px">&gt;</span>

        <input
          id="__input__"
          autocomplete="off"
          style="
            flex:1;
            background:#111;
            color:#eee;
            border:0;
            outline:0;
            font:inherit;
          "
        >
      </div>
    `;

    document.body.appendChild(this.consoleDiv);

    const output = this.consoleDiv.querySelector("#__output__");
    const input = this.consoleDiv.querySelector("#__input__");

    this.history = [];
    this.historyIndex = 0;
    this.output = output;

    input.addEventListener("keydown", event => {

      if (event.key === "Enter") {
        this.execute(input.value);
        input.value = "";
      }

      if (event.key === "ArrowUp") {

        event.preventDefault();

        if (this.historyIndex > 0) {
          this.historyIndex--;
          input.value = this.history[this.historyIndex];
        }
      }

      if (event.key === "ArrowDown") {

        event.preventDefault();

        if (this.historyIndex < this.history.length - 1) {
          this.historyIndex++;
          input.value = this.history[this.historyIndex];

        } else {
          this.historyIndex = this.history.length;
          input.value = "";
        }
      }

        if (event.key === "Tab") {
            event.preventDefault();
            var autocomplete = window.app.TerminalManager.autocomplete(input.value);
            if (autocomplete) {
                input.value = autocomplete;
            }
        }

    });

    this.print("Mini-console démarré.");
    this.print('Tape "help" pour voir les commandes.');

    input.focus();
  }

  execute(command) {

    command = command.trim();

    if (!command) {
      return;
    }

    this.history.push(command);
    this.historyIndex = this.history.length;

    this.print("> " + command);

    const [name, ...args] = command.split(/\s+/);

    window.app.TerminalManager.execute(`${name}`, ...args);
  }

  print = (text) => {
      this.output.textContent += text + "\n";
      this.output.scrollTop = this.output.scrollHeight;
  }

}
