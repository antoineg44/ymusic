import { Component } from '@angular/core';
import { IonHeader, IonToolbar, IonTitle, IonContent } from '@ionic/angular/standalone';
import { AfterViewInit } from '@angular/core';
interface AppMessage {
  type: string;
  payload?: unknown;
}

@Component({
  selector: 'app-home',
  templateUrl: 'home.page.html',
  styleUrls: ['home.page.scss'],
  imports: [IonHeader, IonToolbar, IonTitle, IonContent],
})

export class HomePage implements AfterViewInit {
  constructor() {
    //window.addEventListener("message", this.onMessage);
  }

  // Pour les message :
  private onMessage = (event: MessageEvent<AppMessage>): void => {
    console.log("Message reçu :", event.data);
  };

  ngOnDestroy(): void {
    window.removeEventListener("message", this.onMessage);
  }


  // Pour l'initialisation
  ngAfterViewInit() {
    console.log('Vue initialisée');
    window.postMessage({type:"INITIALIZATION_DONE"}, "*");
  }
}
