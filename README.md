# ymusic


# Android :
## Generer dans Android Studio
export CAPACITOR_ANDROID_STUDIO_PATH="/snap/android-studio/current/bin/studio.sh" && ionic build && npx cap sync android
## Ouvrir Android Studio
npx cap open android
## Générer APK
./android/gradlew assembleDebug
