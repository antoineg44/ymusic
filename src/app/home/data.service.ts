import { Injectable } from '@angular/core';

interface LoginData {
  username: string;
  password: string;
}

@Injectable({
  providedIn: 'root'
})
export class DataService {

  private readonly STORAGE_KEY = 'loginData';

  setData(data: LoginData): void {
    localStorage.setItem(this.STORAGE_KEY, JSON.stringify(data));
  }

  getData(): LoginData {
    const data = localStorage.getItem(this.STORAGE_KEY);

    if (!data) {
      return {
        username: '',
        password: ''
      };
    }

    try {
      return JSON.parse(data);
    } catch {
      return {
        username: '',
        password: ''
      };
    }
  }

  clearData(): void {
    localStorage.removeItem(this.STORAGE_KEY);
  }
}