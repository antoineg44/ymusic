import { Injectable } from '@angular/core';

@Injectable({
  providedIn: 'root'
})
export class DataService {

  // Jeton de persistance SSO (remember-me) stocke sur l'appareil, rotatif et revocable.
  private readonly REMEMBER_KEY = 'ssoRememberToken';

  setRememberToken(token: string): void {
    if (token) {
      localStorage.setItem(this.REMEMBER_KEY, token);
    }
  }

  getRememberToken(): string {
    return localStorage.getItem(this.REMEMBER_KEY) || '';
  }

  clearRememberToken(): void {
    localStorage.removeItem(this.REMEMBER_KEY);
  }
}