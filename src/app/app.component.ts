import { Component } from '@angular/core';
import { IonApp, IonRouterOutlet } from '@ionic/angular/standalone';
import { Capacitor } from '@capacitor/core';
import { LocalNotifications } from '@capacitor/local-notifications';

@Component({
  selector: 'app-root',
  templateUrl: 'app.component.html',
  imports: [IonApp, IonRouterOutlet],
})
export class AppComponent {
  constructor() {
    void this.requestNotificationPermission();
  }

  // Demande l'autorisation de notification (POST_NOTIFICATIONS sur Android 13+) pour la notification media.
  private async requestNotificationPermission(): Promise<void> {
    if (!Capacitor.isNativePlatform()) {
      return;
    }

    try {
      const status = await LocalNotifications.checkPermissions();
      if (status.display !== 'granted') {
        await LocalNotifications.requestPermissions();
      }
    } catch (error) {
      console.debug('Notification permission request failed:', error);
    }
  }
}
