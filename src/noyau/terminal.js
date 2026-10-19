class Terminal {

  constructor() {
    this.commands = {};

    this.addCommand("h", this.help, "Affiche cette aide");
    this.addCommand("help", this.help, "Affiche cette aide");
  }
  
  execute(name, ...args) {
    const command = this.commands[name];

    if (command && typeof command.fn === "function") {
      return command.fn.call(this, ...args);
    }

    this.print(`Commande inconnue : ${name}`);
  }

  print(text) {
    window.app.Console.print(text);
  }

  addCommand(name, fn, description) {
    this.commands[name] = {
      fn: fn.bind(this),
      description
    };
  }

  addSubCommand(classObject, name, description) {
    this.commands[name] = {
      fn: (...args) => {
                const [first, ...rest] = args;
                classObject.execute(first, ...rest);
      },
      description,
      parent: classObject
    };
  }

  help() {
    for (const [name, command] of Object.entries(this.commands)) {
      this.print(`${name.padEnd(10)} ${command.description}`);
    }
  }

  autocomplete(input) {

    // On ne s'intéresse qu'au premier mot
    const parts = input.split(/\s+/);

    const current = parts[0].toLowerCase();

    if (!current) {
        const matches = Object.keys(this.commands).sort();
        // Toutes les possibilités
        this.print(
            matches.join("    ")
        );
        return;
    }

    // Cherche les commandes correspondantes
    const matches = Object.keys(this.commands)
        .filter(command =>
            command.toLowerCase().startsWith(current)
        )
        .sort();

    // Aucune correspondance
    if (matches.length === 0) {
        return;
    }

    // Une seule correspondance
    if (matches.length === 1) {
        // S'il y a déjà des arguments
        if (parts.length > 1 || matches[0] === current) {
          if(this.commands[matches].parent) {
            var result = this.commands[matches].parent.autocomplete(parts.slice(1).join(" "));
            if(result) {
              return matches[0] + " " + result;
            }
          }
          return;
        }
        else {
            return matches[0] + " ";
        }
    }

    // Plusieurs correspondances
    this.print(
        matches.join("    ")
    );

    this.print("");

    // Cherche le préfixe commun
    const commonPrefix =
        this.getCommonPrefix(matches);

    if (commonPrefix.length > current.length) {

        return commonPrefix;
    }
  }

  getCommonPrefix(strings) {

    if (strings.length === 0) {
        return "";
    }

    let prefix = strings[0];

    for (let i = 1; i < strings.length; i++) {

        while (
            !strings[i].startsWith(prefix)
        ) {
            prefix =
                prefix.slice(0, -1);

            if (!prefix) {
                return "";
            }
        }
    }

    return prefix;
  }


}
