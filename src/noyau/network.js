const CONNEXION_TYPE = {
    CLIENT: "client",
    MOBILE: "mobile",
    SERVER: "server"
};

window.app.CONNEXION_TYPE = CONNEXION_TYPE;

class NetworkController {

    constructor() {
        this.connectionType = null;
        this.type();
    }

    type() {

        if (window.location.protocol === "file:") {

            this.connectionType =
                CONNEXION_TYPE.CLIENT;

        }
        else if (
            window.location.protocol === "https:" &&
            window.location.hostname === "localhost"
        ) {

            this.connectionType =
                CONNEXION_TYPE.MOBILE;

        }
        else {

            const domain_approv = [
                "localhost",
                "192.168.1.10",
                "musiques.partitions.ovh"
            ];

            if (
                domain_approv.includes(
                    window.location.hostname
                )
            ) {

                this.connectionType =
                    CONNEXION_TYPE.SERVER;

            }
            else {

                this.connectionType =
                    CONNEXION_TYPE.MOBILE;
            }
        }

        return this.connectionType;
    }


    async test() {

        try {

            await fetch("/health", {
                method: "HEAD",
                cache: "no-store"
            });

            return true;

        }
        catch {

            return false;
        }
    }
}


// Rend la classe accessible depuis le code de l'application
window.app.NetworkController = new NetworkController();

class NetworkTerminal extends Terminal {

    constructor() {

        super();

        window.app.TerminalManager.addSubCommand(this, "network", "Affiche le type de connexion");

        this.addCommand(
            "info",
            () => {

                this.print(
                    window.app.NetworkController.connectionType
                );
            },
            "Affiche le type de connexion",
            
        );

        this.addCommand(
            "test",
            async () => {

                this.print("Test en cours...");

                const result =
                    await window.app.NetworkController.test();

                this.print(
                    result
                        ? "Serveur disponible"
                        : "Serveur inaccessible"
                );
            },
            "Teste la connexion au serveur"
        );
    }
}

new NetworkTerminal();