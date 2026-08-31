(() => {
	const TRIM_SETTING_KEY = 'ymusic.trimLowIntroOutro';
	const CROSSFADE_SECONDS_KEY = 'ymusic.crossfadeSeconds';
	const DEFAULT_CROSSFADE_SECONDS = 0;
	const QUIET_LEVEL_THRESHOLD = 0.018;
	const INTRO_SCAN_LIMIT_RATIO = 0.10;
	const INTRO_SCAN_MIN_SECONDS = 5;
	const INTRO_SCAN_MAX_SECONDS = 16;
	const INTRO_SKIP_RATIO = 0.16;
	const INTRO_SKIP_MIN_SECONDS = 0.35;
	const INTRO_SKIP_MAX_SECONDS = 1.2;
	const OUTRO_SCAN_WINDOW_RATIO = 0.08;
	const OUTRO_SCAN_MIN_SECONDS = 4;
	const OUTRO_SCAN_MAX_SECONDS = 10;
	const OUTRO_SKIP_RATIO = 0.68;
	const OUTRO_SKIP_MAX_SECONDS = 5;
	const OUTRO_SKIP_END_OFFSET_SECONDS = 0.12;

	function createPlayController(options) {
		const primaryAudio = options.primaryAudio;
		const secondaryAudio = options.secondaryAudio;

		if (!(primaryAudio instanceof HTMLMediaElement) || !(secondaryAudio instanceof HTMLMediaElement)) {
			throw new Error('createPlayController requires two valid audio elements.');
		}

		let wakeLock = null;
		let trimQuietPartsEnabled = false;
		let crossfadeSeconds = DEFAULT_CROSSFADE_SECONDS;
		let autoChainRequested = false;
		let activeAudio = primaryAudio;
		let audioContext = null;
		let analyserNode = null;
		let analyserData = null;
		const audioSourceNodes = new WeakMap();
		const audioGainNodes = new WeakMap();
		let analyserAttachedAudio = null;
		const fadeFrameIds = new WeakMap();
		let fadeIndicatorCount = 0;
		let crossfadeToken = 0;
		let introLowCount = 0;
		let outroLowCount = 0;
		let lastIntroSkipAt = 0;
		let introSkipped = false;
		let outroSkipped = false;

		function emitPlayState(isPlaying) {
			if (typeof options.onPlayStateChange === 'function') {
				options.onPlayStateChange(Boolean(isPlaying));
			}
		}

		function emitTimeUpdate(payload) {
			if (typeof options.onTimeUpdate === 'function') {
				options.onTimeUpdate(payload);
			}
		}

		function emitAutoNext(fadeSeconds) {
			if (typeof options.onAutoNext === 'function') {
				options.onAutoNext({ fadeSeconds });
			}
		}

		function emitPlaybackError(error) {
			if (typeof options.onPlaybackError === 'function') {
				options.onPlaybackError(String(error || 'Erreur de lecture.'));
			}
		}

		function emitFadeIndicatorState() {
			if (typeof options.onFadeIndicatorChange === 'function') {
				options.onFadeIndicatorChange(fadeIndicatorCount > 0);
			}
		}

		function getInactiveAudio() {
			return activeAudio === primaryAudio ? secondaryAudio : primaryAudio;
		}

		function readTrimQuietPartsSetting() {
			try {
				return window.localStorage.getItem(TRIM_SETTING_KEY) === '1';
			} catch (error) {
				console.debug('Trim setting read failed:', error);
				return false;
			}
		}

		function readCrossfadeSecondsSetting() {
			try {
				const rawValue = window.localStorage.getItem(CROSSFADE_SECONDS_KEY);
				const numericValue = Number.parseInt(rawValue === null ? String(DEFAULT_CROSSFADE_SECONDS) : rawValue, 10);
				if (!Number.isFinite(numericValue)) {
					return DEFAULT_CROSSFADE_SECONDS;
				}

				return Math.min(12, Math.max(0, numericValue));
			} catch (error) {
				console.debug('Crossfade setting read failed:', error);
				return DEFAULT_CROSSFADE_SECONDS;
			}
		}

		function refreshSettings() {
			trimQuietPartsEnabled = readTrimQuietPartsSetting();
			crossfadeSeconds = readCrossfadeSecondsSetting();
		}

		function resetTrimDetectionState() {
			introLowCount = 0;
			outroLowCount = 0;
			lastIntroSkipAt = 0;
			introSkipped = false;
			outroSkipped = false;
			autoChainRequested = false;
		}

		function beginFadeIndicator() {
			fadeIndicatorCount += 1;
			emitFadeIndicatorState();
		}

		function endFadeIndicator() {
			fadeIndicatorCount = Math.max(0, fadeIndicatorCount - 1);
			emitFadeIndicatorState();
		}

		function cancelVolumeFade(targetAudio) {
			const media = targetAudio || activeAudio;
			const frameId = fadeFrameIds.get(media);
			if (frameId !== undefined && frameId !== null) {
				window.cancelAnimationFrame(frameId);
				fadeFrameIds.delete(media);
				endFadeIndicator();
			}
		}

		function cancelAllVolumeFades() {
			cancelVolumeFade(primaryAudio);
			cancelVolumeFade(secondaryAudio);
			fadeIndicatorCount = 0;
			emitFadeIndicatorState();
		}

		function fadeAudioVolume(targetAudio, targetVolume, durationSeconds, onComplete) {
			const media = targetAudio || activeAudio;
			cancelVolumeFade(media);

			const clampedTarget = Math.min(1, Math.max(0, Number(targetVolume || 0)));
			const safeDuration = Math.max(0, Number(durationSeconds || 0));

			if (safeDuration <= 0.01) {
				setMediaVolume(media, clampedTarget);
				if (typeof onComplete === 'function') {
					onComplete();
				}
				return;
			}

			beginFadeIndicator();

			const startVolume = getMediaVolume(media);
			const startTime = performance.now();
			const durationMs = safeDuration * 1000;

			const step = (now) => {
				const elapsed = now - startTime;
				const progress = Math.min(1, elapsed / durationMs);
				setMediaVolume(media, startVolume + ((clampedTarget - startVolume) * progress));

				if (progress < 1) {
					const nextFrameId = window.requestAnimationFrame(step);
					fadeFrameIds.set(media, nextFrameId);
				} else {
					fadeFrameIds.delete(media);
					endFadeIndicator();
					if (typeof onComplete === 'function') {
						onComplete();
					}
				}
			};

			const frameId = window.requestAnimationFrame(step);
			fadeFrameIds.set(media, frameId);
		}

		function ensureAudioContext() {
			const AudioContextClass = window.AudioContext || window.webkitAudioContext;
			if (!AudioContextClass) {
				return false;
			}

			try {
				audioContext = audioContext || new AudioContextClass();
				return true;
			} catch (error) {
				console.debug('Audio context init failed:', error);
				return false;
			}
		}

		// Construit source -> gain -> sortie pour un element audio ; le volume passe par le GainNode
		// car un element route via Web Audio n'obeit plus a sa propriete .volume.
		function ensureAudioNodes(media) {
			if (!ensureAudioContext()) {
				return null;
			}

			let source = audioSourceNodes.get(media);
			if (!source) {
				try {
					source = audioContext.createMediaElementSource(media);
				} catch (error) {
					console.debug('Media source init failed:', error);
					return null;
				}

				const gainNode = audioContext.createGain();
				gainNode.gain.value = Number.isFinite(media.volume) ? media.volume : 1;
				source.connect(gainNode);
				gainNode.connect(audioContext.destination);
				audioSourceNodes.set(media, source);
				audioGainNodes.set(media, gainNode);
			}

			return source;
		}

		function setMediaVolume(media, value) {
			const clamped = Math.min(1, Math.max(0, Number(value || 0)));
			const gainNode = audioGainNodes.get(media);
			if (gainNode) {
				gainNode.gain.value = clamped;
			} else {
				media.volume = clamped;
			}
		}

		function getMediaVolume(media) {
			const gainNode = audioGainNodes.get(media);
			if (gainNode) {
				return gainNode.gain.value;
			}
			return Number.isFinite(media.volume) ? media.volume : 1;
		}

		function ensureAudioAnalyser() {
			if (!ensureAudioContext()) {
				return false;
			}

			try {
				if (!analyserNode) {
					analyserNode = audioContext.createAnalyser();
					analyserNode.fftSize = 2048;
					// L'analyseur est un simple point de mesure (non relie a la sortie).
					analyserData = new Uint8Array(analyserNode.fftSize);
				}

				if (!ensureAudioNodes(activeAudio)) {
					return false;
				}

				if (analyserAttachedAudio !== activeAudio) {
					if (analyserAttachedAudio) {
						const previousSource = audioSourceNodes.get(analyserAttachedAudio);
						if (previousSource) {
							previousSource.disconnect(analyserNode);
						}
					}

					const source = audioSourceNodes.get(activeAudio);
					if (source) {
						source.connect(analyserNode);
					}
					analyserAttachedAudio = activeAudio;
				}

				return true;
			} catch (error) {
				console.debug('Audio analyser init failed:', error);
				return false;
			}
		}

		function getCurrentSignalLevel() {
			if (!analyserNode || !analyserData) {
				return null;
			}

			analyserNode.getByteTimeDomainData(analyserData);
			let squaredSum = 0;

			for (let index = 0; index < analyserData.length; index += 1) {
				const centered = (analyserData[index] - 128) / 128;
				squaredSum += centered * centered;
			}

			return Math.sqrt(squaredSum / analyserData.length);
		}

		function maybeTrimQuietSections() {
			const media = activeAudio;
			if (!trimQuietPartsEnabled || media.paused || !Number.isFinite(media.duration) || media.duration <= 0) {
				return;
			}

			if (!ensureAudioAnalyser()) {
				return;
			}

			const level = getCurrentSignalLevel();
			if (level === null) {
				return;
			}

			const currentTime = Number(media.currentTime || 0);
			const duration = Number(media.duration || 0);
			const remaining = Math.max(0, duration - currentTime);
			const introScanLimit = Math.min(
				INTRO_SCAN_MAX_SECONDS,
				Math.max(INTRO_SCAN_MIN_SECONDS, duration * INTRO_SCAN_LIMIT_RATIO)
			);
			const outroScanWindow = Math.min(
				OUTRO_SCAN_MAX_SECONDS,
				Math.max(OUTRO_SCAN_MIN_SECONDS, duration * OUTRO_SCAN_WINDOW_RATIO)
			);
			// Fondu anticipe : si la troncature et le fondu sont actifs, on scrute la fin plus tot
			// (fenetre elargie de la duree du fondu) pour enchainer des le debut du silence de fin.
			const anticipateCrossfade = trimQuietPartsEnabled && crossfadeSeconds > 0 && !autoChainRequested;
			const outroScanRegion = anticipateCrossfade
				? Math.min(duration * 0.5, crossfadeSeconds + outroScanWindow)
				: outroScanWindow;

			if (!introSkipped && currentTime <= introScanLimit) {
				if (level < QUIET_LEVEL_THRESHOLD) {
					introLowCount += 1;
				} else {
					introLowCount = 0;
				}

				if (introLowCount >= 3 && currentTime - lastIntroSkipAt >= 0.8) {
					const remainingIntroWindow = Math.max(0, introScanLimit - currentTime);
					const introStep = Math.min(
						INTRO_SKIP_MAX_SECONDS,
						Math.max(INTRO_SKIP_MIN_SECONDS, remainingIntroWindow * INTRO_SKIP_RATIO)
					);
					const target = Math.min(introScanLimit, currentTime + introStep);
					if (target > currentTime + 0.2) {
						media.currentTime = target;
						lastIntroSkipAt = target;
						introLowCount = 0;
						introSkipped = true;
						return;
					}
				}
			} else {
				introLowCount = 0;
			}

			if (!outroSkipped && remaining <= outroScanRegion && remaining > OUTRO_SKIP_END_OFFSET_SECONDS) {
				if (level < QUIET_LEVEL_THRESHOLD) {
					outroLowCount += 1;
				} else {
					outroLowCount = 0;
				}

				if (outroLowCount >= 4) {
					if (anticipateCrossfade) {
						// On demarre le fondu croise des la fin musicale detectee, sans sauter le silence.
						autoChainRequested = true;
						outroSkipped = true;
						emitAutoNext(crossfadeSeconds);
						return;
					}

					const outroStep = Math.min(OUTRO_SKIP_MAX_SECONDS, Math.max(0.5, remaining * OUTRO_SKIP_RATIO));
					const target = Math.min(duration - OUTRO_SKIP_END_OFFSET_SECONDS, currentTime + outroStep);

					if (target > currentTime + 0.2) {
						media.currentTime = target;
						outroSkipped = true;
					}
				}
			}
		}

		async function requestWakeLock() {
			if (!navigator.wakeLock) {
				return;
			}

			try {
				wakeLock = await navigator.wakeLock.request('screen');
			} catch (error) {
				console.debug('Wake Lock request failed:', error);
			}
		}

		function releaseWakeLock() {
			if (!wakeLock) {
				return;
			}

			try {
				wakeLock.release();
				wakeLock = null;
			} catch (error) {
				console.debug('Wake Lock release failed:', error);
			}
		}

		function getPlayedSeconds(media) {
			const ranges = media.played;
			let total = 0;
			for (let index = 0; index < ranges.length; index += 1) {
				total += Math.max(0, ranges.end(index) - ranges.start(index));
			}
			return total;
		}

		function updateTimeDisplay() {
			const media = activeAudio;
			maybeTrimQuietSections();

			if (!autoChainRequested && crossfadeSeconds > 0 && Number.isFinite(media.duration) && media.duration > 0) {
				const remaining = Math.max(0, Number(media.duration) - Number(media.currentTime || 0));
				if (remaining <= crossfadeSeconds) {
					autoChainRequested = true;
					emitAutoNext(crossfadeSeconds);
				}
			}

			emitTimeUpdate({
				currentTime: media.currentTime || 0,
				duration: media.duration || 0,
				playedSeconds: getPlayedSeconds(media),
			});
		}

		function cleanupOutgoingAudio(outgoingAudio) {
			outgoingAudio.pause();
			outgoingAudio.currentTime = 0;
			setMediaVolume(outgoingAudio, 1);
			outgoingAudio.removeAttribute('src');
			outgoingAudio.load();
		}

		function normalizeTrackSrc(src) {
			const normalized = String(src || '').trim();
			if (!normalized) {
				return '';
			}

			if (normalized.startsWith('../../')) {
				return normalized;
			}

			return `../../${normalized}`;
		}

		function loadTrack({ src, fadeInSeconds }) {
			refreshSettings();
			resetTrimDetectionState();
			crossfadeToken += 1;
			cancelAllVolumeFades();

			const incomingAudio = getInactiveAudio();
			const outgoingAudio = activeAudio;
			const safeFadeInSeconds = Math.max(0, Number(fadeInSeconds || 0));
			const canCrossfade = safeFadeInSeconds > 0 && Boolean(outgoingAudio.src) && !outgoingAudio.paused;
			const token = crossfadeToken;

			var base_url = get_url_from_base();

			if(base_url == "")base_url = "../../";

			incomingAudio.crossOrigin = "anonymous";
			const rawSrc = String(src || '').trim();
			let finalSrc = rawSrc;
			// Ne pas préfixer les URLs absolues ou spéciales (blob:, data:, http(s)://, /)
			if (!/^\s*(?:blob:|data:|https?:\/\/|\/)\s*/i.test(rawSrc)) {
				finalSrc = base_url + rawSrc;
			}
			incomingAudio.src = finalSrc;
			incomingAudio.currentTime = 0;
			incomingAudio.load();

			// Prepare le graphe (source -> gain -> sortie) pour piloter le volume des deux pistes via GainNode.
			ensureAudioNodes(outgoingAudio);
			ensureAudioNodes(incomingAudio);
			setMediaVolume(incomingAudio, canCrossfade ? 0 : 1);

			activeAudio = incomingAudio;

			console.log("incomingAudio");
			console.log(incomingAudio);
			console.log(src);

			incomingAudio.play().catch(() => {
				emitPlaybackError('Lecture bloquee par le navigateur.');
			});

			if (canCrossfade) {
				// Les deux musiques jouent en meme temps pendant toute la duree du fondu :
				// la sortante part du maximum et descend vers 0, l'entrante part de 0 et monte vers le maximum.
				setMediaVolume(outgoingAudio, 1);
				fadeAudioVolume(incomingAudio, 1, safeFadeInSeconds);
				fadeAudioVolume(outgoingAudio, 0, safeFadeInSeconds, () => {
					if (token !== crossfadeToken) {
						return;
					}
					cleanupOutgoingAudio(outgoingAudio);
				});
			} else {
				cleanupOutgoingAudio(outgoingAudio);
			}

			if (ensureAudioAnalyser() && audioContext && audioContext.state === 'suspended') {
				void audioContext.resume();
			}

			emitPlayState(true);
			void requestWakeLock();
			updateTimeDisplay();
		}

		function fadeOut(durationSeconds) {
			const safeDurationSeconds = Math.max(0, Number(durationSeconds || 0));
			if (safeDurationSeconds > 0) {
				fadeAudioVolume(activeAudio, 0, safeDurationSeconds);
			}
		}

		function togglePlayback() {
			const media = activeAudio;
			if (!media.src) {
				return { missingSource: true };
			}

			if (media.paused) {
				media.play().catch(() => {
					emitPlaybackError('Lecture bloquee par le navigateur.');
				});
				const otherMedia = getInactiveAudio();
				if (otherMedia.src && getMediaVolume(otherMedia) > 0.01 && otherMedia.paused) {
					otherMedia.play().catch(() => {});
				}
				emitPlayState(true);
			} else {
				media.pause();
				const otherMedia = getInactiveAudio();
				if (!otherMedia.paused) {
					otherMedia.pause();
				}
				emitPlayState(false);
			}

			return { missingSource: false };
		}

		function seekToRatio(ratio) {
			const media = activeAudio;
			if (!media.duration) {
				return;
			}

			const safeRatio = Math.min(1, Math.max(0, Number(ratio || 0)));
			media.currentTime = (media.duration || 0) * safeRatio;
			updateTimeDisplay();
		}

		function setExternalPlayState(isPlaying) {
			emitPlayState(Boolean(isPlaying));
			if (isPlaying) {
				void requestWakeLock();
			} else {
				releaseWakeLock();
			}
		}

		function attachAudioEvents(media) {
			media.addEventListener('timeupdate', () => {
				if (media !== activeAudio) {
					return;
				}
				updateTimeDisplay();
			});

			media.addEventListener('loadedmetadata', () => {
				if (media !== activeAudio) {
					return;
				}
				updateTimeDisplay();
			});

			media.addEventListener('ended', () => {
				if (media !== activeAudio) {
					return;
				}
				resetTrimDetectionState();
				emitAutoNext(crossfadeSeconds);
			});

			media.addEventListener('play', () => {
				if (media !== activeAudio) {
					return;
				}
				emitPlayState(true);
				if (ensureAudioAnalyser() && audioContext && audioContext.state === 'suspended') {
					void audioContext.resume();
				}
				void requestWakeLock();
			});

			media.addEventListener('pause', () => {
				if (media !== activeAudio) {
					return;
				}
				emitPlayState(false);
				releaseWakeLock();
			});
		}

		attachAudioEvents(primaryAudio);
		attachAudioEvents(secondaryAudio);
		refreshSettings();

		return {
			refreshSettings,
			togglePlayback,
			seekToRatio,
			loadTrack,
			fadeOut,
			setExternalPlayState,
			getCrossfadeSeconds: () => crossfadeSeconds,
		};
	}

	window.createPlayController = createPlayController;
})();
