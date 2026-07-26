#  liste des commandes

ionic start --type=angular
cd ymusic
ionic serve
ionic build

npm install @capacitor/android
npx cap add android
npx cap sync android
npx cap open android
ionic cap run android