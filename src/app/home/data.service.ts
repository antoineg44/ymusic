// data.service.ts
import { Injectable } from '@angular/core';

@Injectable({
  providedIn: 'root'
})
export class DataService {
  private storedData: any;

  setData(data: any): void {
    this.storedData = data;
  }

  getData(): any {
    return this.storedData;
  }
}