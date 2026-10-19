class TerminalManager extends Terminal {

    constructor() {
        super();

        this.addCommand("c", this.clear, "Efface le terminal");
        this.addCommand("clear", this.clear, "Efface le terminal");

        this.addCommand("e", this.eval, "Exécute du JavaScript");
        this.addCommand("eval", this.eval, "Exécute du JavaScript");

        this.addCommand("q", this.exit, "Ferme le terminal");
        this.addCommand("exit", this.exit, "Ferme le terminal");
    }

  clear() {
    window.app.Console.output.textContent = "";
  }

  eval(...args) {
    try {
      const result = window.eval(args.join(" "));

      if (result !== undefined) {
        this.print(String(result));
      }
    } catch (error) {
      this.print("Erreur : " + error.message);
    }
  }

  exit() {
    window.app.Console.consoleDiv.remove();
  }
}

window.app.TerminalManager = new TerminalManager();