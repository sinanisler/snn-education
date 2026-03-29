(function($) {
    'use strict';

    // ===========================================================
    // GLOBAL API FUNCTIONS
    // ===========================================================

    window.snnEduEnrollUser = function(postId) {
        return fetch(snnEduData.restUrl + 'v1/enroll', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': snnEduData.nonce },
            body: JSON.stringify({ post_id: postId })
        }).then(r => r.json()).then(data => {
            if (data.success) document.dispatchEvent(new CustomEvent('snn_edu_enrolled', { detail: { postId } }));
            return data;
        });
    };

    window.snnEduUnenrollUser = function(postId) {
        return fetch(snnEduData.restUrl + 'v1/unenroll', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': snnEduData.nonce },
            body: JSON.stringify({ post_id: postId })
        }).then(r => r.json());
    };

    window.snnEduGetEnrollments = function() {
        return fetch(snnEduData.restUrl + 'v1/enrollments', {
            headers: { 'X-WP-Nonce': snnEduData.nonce }
        }).then(r => r.json()).then(data => data.enrollments || []);
    };

    window.snnEduIsEnrolled = function(postId) {
        return snnEduGetEnrollments().then(e => e.includes(postId));
    };

    window.snnEduCompletePost = function(postId) {
        return fetch(snnEduData.restUrl + 'v1/complete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': snnEduData.nonce },
            body: JSON.stringify({ course_id: postId })
        }).then(r => r.json()).then(data => {
            if (data.success) document.dispatchEvent(new CustomEvent('snn_edu_completed', { detail: { postId } }));
            return data;
        });
    };

    window.snnEduGetCompletions = function() {
        return fetch(snnEduData.restUrl + 'v1/completions', {
            headers: { 'X-WP-Nonce': snnEduData.nonce }
        }).then(r => r.json()).then(data => data.completions || []);
    };

    window.snnEduIsCompleted = function(postId) {
        return snnEduGetCompletions().then(c => c.some(x => x.course_id == postId));
    };

    // ===========================================================
    // VIDEO PLAYER
    // ===========================================================

    const snn_education_videoPlayers = [];

    function snn_education_initVideoPlayer(playerWrapper) {
        if (!playerWrapper || playerWrapper.dataset.snnPlayerInitialized) return;
        playerWrapper.dataset.snnPlayerInitialized = 'true';

        // Tracking config
        const lessonId    = playerWrapper.dataset.lessonId;
        const events      = playerWrapper.dataset.events || 'both';
        const threshold   = parseFloat(playerWrapper.dataset.threshold) || 3;
        const requireFull = playerWrapper.dataset.requireFull === 'true';

        // Player config
        const chapters        = JSON.parse(playerWrapper.dataset.chapters || '[]');
        const disableAutohide = playerWrapper.dataset.disableAutohide === 'true';

        // Icons
        const ICONS = {
            play:        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7 6v12l10-6z"/></svg>',
            pause:       '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>',
            volumeHigh:  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>',
            volumeMute:  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71zM4.27 3 3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4 9.91 6.09 12 8.18V4z"/></svg>',
            check:       '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>',
        };

        // Elements
        const videoContainer         = playerWrapper.querySelector('.snn-video-container');
        const video                  = playerWrapper.querySelector('.snn-video');
        const playPauseBtn           = playerWrapper.querySelector('.snn-play-pause-btn');
        const muteBtn                = playerWrapper.querySelector('.snn-mute-btn');
        const volumeSlider           = playerWrapper.querySelector('.snn-volume-slider');
        const fullscreenBtn          = playerWrapper.querySelector('.snn-fullscreen-btn');
        const progressBar            = playerWrapper.querySelector('.snn-progress-bar');
        const progressThumb          = playerWrapper.querySelector('.snn-progress-thumb');
        const timeDisplay            = playerWrapper.querySelector('.snn-time-display');
        const chapterDotsContainer   = playerWrapper.querySelector('.snn-chapter-dots-container');
        const chapterSectionsEl      = playerWrapper.querySelector('.snn-chapter-sections-container');
        const progressTooltip        = playerWrapper.querySelector('.snn-progress-tooltip');
        const fullscreenIcon         = playerWrapper.querySelector('.snn-fullscreen-icon');
        const fullscreenExitIcon     = playerWrapper.querySelector('.snn-fullscreen-exit-icon');
        const progressContainer      = playerWrapper.querySelector('.snn-progress-container');
        const ccBtn                  = playerWrapper.querySelector('.snn-cc-btn');
        const ccMenu                 = playerWrapper.querySelector('.snn-cc-menu');
        const ccSettingsBtn          = playerWrapper.querySelector('.snn-cc-settings-btn');
        const ccSettingsPanel        = playerWrapper.querySelector('.snn-cc-settings-panel');
        const ccLangList             = playerWrapper.querySelector('.snn-cc-lang-list');
        const ccBackBtn              = playerWrapper.querySelector('.snn-cc-back-btn');
        const ccFontSizeInput        = playerWrapper.querySelector('.snn-cc-font-size');
        const ccFontSizeValue        = playerWrapper.querySelector('.snn-cc-font-size-value');
        const ccTextColorInput       = playerWrapper.querySelector('.snn-cc-text-color');
        const ccBgColorInput         = playerWrapper.querySelector('.snn-cc-bg-color');
        const ccBgOpacityInput       = playerWrapper.querySelector('.snn-cc-bg-opacity');
        const ccBgOpacityValue       = playerWrapper.querySelector('.snn-cc-bg-opacity-value');
        const settingsBtn            = playerWrapper.querySelector('.snn-settings-btn');
        const settingsMenu           = playerWrapper.querySelector('.snn-settings-menu');
        const speedOptions           = playerWrapper.querySelectorAll('.snn-speed-option');

        if (!video || !videoContainer || !playPauseBtn || !progressThumb) return;

        let isSeeking       = false;
        let inactivityTimer = null;
        let lastVolume      = 1;
        let chapterSections = [];
        let playPromise     = null;

        // Tracking state
        let hasStarted  = false;
        let hasCompleted = false;
        let watchedTime = 0;
        let lastUpdateTime = 0;

        // Helpers
        const timeToSeconds = (t) => {
            if (!t || typeof t !== 'string') return 0;
            const parts = t.split(':').map(Number);
            return parts.length === 2 ? parts[0] * 60 + parts[1] : 0;
        };
        const formatTime = (s) => {
            if (isNaN(s) || s < 0) return '00:00';
            return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(Math.floor(s % 60)).padStart(2, '0');
        };

        // Set initial icons
        playPauseBtn.innerHTML = ICONS.play;
        muteBtn.innerHTML      = ICONS.volumeHigh;

        // ---- Controls visibility ----
        const showControls = () => {
            videoContainer.classList.add('snn-controls-visible');
            videoContainer.classList.remove('snn-controls-hidden');
        };
        const hideControls = () => {
            if (video.paused) return;
            videoContainer.classList.remove('snn-controls-visible');
            videoContainer.classList.add('snn-controls-hidden');
        };
        const resetInactivity = () => {
            showControls();
            clearTimeout(inactivityTimer);
            if (!disableAutohide) inactivityTimer = setTimeout(hideControls, 3000);
        };

        videoContainer.addEventListener('mousemove', resetInactivity);
        videoContainer.addEventListener('mouseenter', showControls);
        videoContainer.addEventListener('mouseleave', () => { if (!video.paused && !disableAutohide) hideControls(); });
        showControls();

        // ---- Play / Pause ----
        const togglePlay = async () => {
            if (video.paused) {
                playPromise = video.play();
                if (playPromise) await playPromise.catch(() => {});
            } else {
                video.pause();
            }
        };

        playPauseBtn.addEventListener('click', (e) => { e.stopPropagation(); togglePlay(); });
        videoContainer.addEventListener('click', (e) => {
            if (e.target === video || e.target === videoContainer) togglePlay();
        });

        video.addEventListener('play', () => { playPauseBtn.innerHTML = ICONS.pause; resetInactivity(); });
        video.addEventListener('pause', () => { playPauseBtn.innerHTML = ICONS.play; showControls(); });

        // ---- Progress ----
        const updateProgressUI = () => {
            if (!video.duration || isSeeking) return;
            const pct = (video.currentTime / video.duration) * 100;
            progressBar.value          = pct;
            progressThumb.style.left   = pct + '%';
            timeDisplay.textContent    = formatTime(video.currentTime) + ' / ' + formatTime(video.duration);
            updateChapterFills();
        };

        video.addEventListener('timeupdate', () => {
            updateProgressUI();

            const now = Date.now();
            if (lastUpdateTime && now - lastUpdateTime < 2000) {
                watchedTime += (now - lastUpdateTime) / 1000;
            }
            lastUpdateTime = now;

            if (!hasStarted && watchedTime >= threshold && (events === 'both' || events === 'started')) {
                hasStarted = true;
                document.dispatchEvent(new CustomEvent('snn_video_started', { detail: { lessonId } }));
                if (!requireFull) snn_education_trackLesson(lessonId, 'completed');
            }
        });

        video.addEventListener('ended', () => {
            playPauseBtn.innerHTML = ICONS.play;
            showControls();
            if (!hasCompleted && (events === 'both' || events === 'completed')) {
                hasCompleted = true;
                document.dispatchEvent(new CustomEvent('snn_video_completed', { detail: { lessonId } }));
                if (requireFull) snn_education_trackLesson(lessonId, 'completed');
            }
        });

        video.addEventListener('loadedmetadata', () => {
            timeDisplay.textContent = '00:00 / ' + formatTime(video.duration);
            buildChapters();
        });

        // ---- Seeking (drag thumb or click progress bar) ----
        const getPercent = (e) => {
            const rect   = progressContainer.getBoundingClientRect();
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            return Math.max(0, Math.min(100, ((clientX - rect.left) / rect.width) * 100));
        };

        const doSeek = (pct) => {
            progressBar.value        = pct;
            progressThumb.style.left = pct + '%';
            if (video.duration) video.currentTime = (pct / 100) * video.duration;
        };

        const startSeek = (e) => {
            isSeeking = true;
            doSeek(getPercent(e));
            document.addEventListener('mousemove', onSeekMove);
            document.addEventListener('mouseup', endSeek);
            document.addEventListener('touchmove', onSeekMove, { passive: true });
            document.addEventListener('touchend', endSeek);
        };

        const onSeekMove = (e) => {
            if (!isSeeking) return;
            const pct = getPercent(e);
            doSeek(pct);
            showTooltip(pct);
        };

        const endSeek = () => {
            isSeeking = false;
            document.removeEventListener('mousemove', onSeekMove);
            document.removeEventListener('mouseup', endSeek);
            document.removeEventListener('touchmove', onSeekMove);
            document.removeEventListener('touchend', endSeek);
            progressTooltip.style.opacity = '0';
        };

        progressContainer.addEventListener('mousedown', startSeek);
        progressContainer.addEventListener('touchstart', startSeek, { passive: true });

        // ---- Tooltip ----
        const showTooltip = (pct) => {
            if (!video.duration) return;
            const time = (pct / 100) * video.duration;
            let text = formatTime(time);
            if (chapters.length) {
                let label = chapters[0].title;
                for (let i = 0; i < chapters.length; i++) {
                    if (timeToSeconds(chapters[i].time) <= time) label = chapters[i].title;
                }
                text = label;
            }
            progressTooltip.textContent    = text;
            progressTooltip.style.left     = pct + '%';
            progressTooltip.style.opacity  = '1';
        };

        progressContainer.addEventListener('mousemove', (e) => {
            if (!isSeeking) showTooltip(getPercent(e));
        });
        progressContainer.addEventListener('mouseleave', () => {
            if (!isSeeking) progressTooltip.style.opacity = '0';
        });

        // ---- Chapters ----
        const buildChapters = () => {
            if (!chapters.length || !video.duration) return;
            chapterDotsContainer.innerHTML  = '';
            chapterSectionsEl.innerHTML     = '';
            chapterSections                 = [];

            chapters.forEach((ch, i) => {
                const startSec = timeToSeconds(ch.time);
                const endSec   = i < chapters.length - 1 ? timeToSeconds(chapters[i + 1].time) : video.duration;
                const widthPct = ((endSec - startSec) / video.duration) * 100;
                const startPct = (startSec / video.duration) * 100;

                if (i > 0) {
                    const dot = document.createElement('div');
                    dot.className  = 'snn-chapter-dot';
                    dot.style.left = startPct + '%';
                    chapterDotsContainer.appendChild(dot);
                }

                const section = document.createElement('div');
                section.className  = 'snn-chapter-section';
                section.style.width = widthPct + '%';

                const bg   = document.createElement('div');
                bg.className = 'snn-chapter-section-bg';
                const fill = document.createElement('div');
                fill.className = 'snn-chapter-section-fill';

                section.appendChild(bg);
                section.appendChild(fill);
                chapterSectionsEl.appendChild(section);
                chapterSections.push({ fill, startSec, endSec });

                section.addEventListener('click', (e) => {
                    const rect  = section.getBoundingClientRect();
                    const relX  = (e.clientX - rect.left) / rect.width;
                    video.currentTime = startSec + relX * (endSec - startSec);
                    e.stopPropagation();
                });
            });
        };

        const updateChapterFills = () => {
            if (!chapterSections.length || !video.duration) return;
            const t = video.currentTime;
            chapterSections.forEach(({ fill, startSec, endSec }) => {
                if (t >= endSec)      fill.style.width = '100%';
                else if (t <= startSec) fill.style.width = '0%';
                else fill.style.width = ((t - startSec) / (endSec - startSec) * 100) + '%';
            });
        };

        // ---- Volume ----
        const updateVolumeUI = () => {
            const muted = video.muted || video.volume === 0;
            muteBtn.innerHTML = muted ? ICONS.volumeMute : ICONS.volumeHigh;
            volumeSlider.value = muted ? 0 : video.volume;
        };

        muteBtn.addEventListener('click', () => {
            if (video.muted) {
                video.muted   = false;
                video.volume  = lastVolume || 1;
            } else {
                lastVolume  = video.volume;
                video.muted = true;
            }
            updateVolumeUI();
        });

        volumeSlider.addEventListener('input', () => {
            video.volume  = parseFloat(volumeSlider.value);
            video.muted   = video.volume === 0;
            lastVolume    = video.volume || lastVolume;
            updateVolumeUI();
        });

        if (playerWrapper.dataset.muted === 'true') {
            video.muted = true;
            updateVolumeUI();
        }

        // ---- Subtitles / CC ----
        if (ccBtn && ccMenu) {
            // Disable all tracks initially, then enable default
            setTimeout(() => {
                for (const t of video.textTracks) t.mode = 'disabled';
            }, 100);

            ccBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                ccMenu.classList.toggle('snn-show');
                if (settingsMenu) settingsMenu.classList.remove('snn-show');
            });

            ccMenu.querySelectorAll('.snn-cc-menu-item[data-track]').forEach(item => {
                item.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const idx = parseInt(item.dataset.track);
                    for (const t of video.textTracks) t.mode = 'disabled';
                    ccMenu.querySelectorAll('.snn-cc-menu-item[data-track]').forEach(i => {
                        i.classList.remove('snn-active');
                        const chk = i.querySelector('.snn-check-icon');
                        if (chk) chk.remove();
                    });
                    item.classList.add('snn-active');
                    const chkEl = document.createElement('span');
                    chkEl.className   = 'snn-check-icon';
                    chkEl.innerHTML   = ICONS.check;
                    item.prepend(chkEl);
                    if (idx >= 0 && video.textTracks[idx]) video.textTracks[idx].mode = 'showing';
                    ccMenu.classList.remove('snn-show');
                });
            });

            if (ccSettingsBtn && ccSettingsPanel && ccLangList && ccBackBtn) {
                ccSettingsBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    ccLangList.classList.add('snn-hidden');
                    ccSettingsPanel.classList.add('snn-show');
                    ccBackBtn.classList.add('snn-show');
                });
                ccBackBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    ccLangList.classList.remove('snn-hidden');
                    ccSettingsPanel.classList.remove('snn-show');
                    ccBackBtn.classList.remove('snn-show');
                });

                const updateCueStyles = () => {
                    const fs      = ccFontSizeInput ? ccFontSizeInput.value : 20;
                    const txtCol  = ccTextColorInput ? ccTextColorInput.value : '#ffffff';
                    const bgCol   = ccBgColorInput ? ccBgColorInput.value : '#000000';
                    const bgOpHex = ccBgOpacityInput
                        ? Math.round(ccBgOpacityInput.value * 2.55).toString(16).padStart(2, '0')
                        : 'cc';

                    let styleEl = playerWrapper.querySelector('.snn-cue-style');
                    if (!styleEl) {
                        styleEl = document.createElement('style');
                        styleEl.className = 'snn-cue-style';
                        playerWrapper.appendChild(styleEl);
                    }
                    styleEl.textContent = `#${playerWrapper.id} video::cue { font-size:${fs}px; color:${txtCol}; background-color:${bgCol}${bgOpHex}; }`;

                    if (ccFontSizeValue) ccFontSizeValue.textContent    = fs;
                    if (ccBgOpacityValue) ccBgOpacityValue.textContent  = ccBgOpacityInput ? ccBgOpacityInput.value : 80;
                };

                if (ccFontSizeInput)  ccFontSizeInput.addEventListener('input', updateCueStyles);
                if (ccTextColorInput) ccTextColorInput.addEventListener('input', updateCueStyles);
                if (ccBgColorInput)   ccBgColorInput.addEventListener('input', updateCueStyles);
                if (ccBgOpacityInput) ccBgOpacityInput.addEventListener('input', updateCueStyles);
            }
        }

        // ---- Speed ----
        if (settingsBtn && settingsMenu) {
            settingsBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                settingsMenu.classList.toggle('snn-show');
                if (ccMenu) ccMenu.classList.remove('snn-show');
            });

            speedOptions.forEach(opt => {
                opt.addEventListener('click', () => {
                    const speed = parseFloat(opt.dataset.speed);
                    video.playbackRate = speed;
                    settingsBtn.textContent = speed + 'x';
                    speedOptions.forEach(o => o.classList.remove('snn-active'));
                    opt.classList.add('snn-active');
                    settingsMenu.classList.remove('snn-show');
                });
            });
        }

        // ---- Fullscreen ----
        fullscreenBtn.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                videoContainer.requestFullscreen().catch(err => console.warn('Fullscreen:', err));
            } else {
                document.exitFullscreen();
            }
        });

        document.addEventListener('fullscreenchange', () => {
            const isFs = !!document.fullscreenElement;
            if (fullscreenIcon)     fullscreenIcon.classList.toggle('snn-hidden', isFs);
            if (fullscreenExitIcon) fullscreenExitIcon.classList.toggle('snn-hidden', !isFs);
        });

        // ---- Keyboard ----
        videoContainer.setAttribute('tabindex', '0');
        videoContainer.addEventListener('keydown', (e) => {
            switch (e.key) {
                case ' ': case 'k': e.preventDefault(); togglePlay(); break;
                case 'ArrowLeft':   e.preventDefault(); video.currentTime = Math.max(0, video.currentTime - 5); break;
                case 'ArrowRight':  e.preventDefault(); video.currentTime = Math.min(video.duration, video.currentTime + 5); break;
                case 'm': muteBtn.click(); break;
                case 'f': fullscreenBtn.click(); break;
            }
            resetInactivity();
        });

        // ---- Close menus on outside click ----
        document.addEventListener('click', (e) => {
            if (!playerWrapper.contains(e.target)) {
                if (ccMenu)       ccMenu.classList.remove('snn-show');
                if (settingsMenu) settingsMenu.classList.remove('snn-show');
            }
        });

        // ---- Visibility change ----
        document.addEventListener('visibilitychange', () => {
            lastUpdateTime = document.hidden ? 0 : Date.now();
        });

        snn_education_videoPlayers.push({ playerWrapper, video, lessonId });
    }

    function snn_education_trackLesson(lessonId, status) {
        fetch(snnEduData.restUrl + 'v2/track', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': snnEduData.nonce },
            body: JSON.stringify({ lesson_id: lessonId, status: status })
        }).then(r => r.json()).then(data => {
            console.log('Lesson tracked:', status, data);
        });
    }

    // ===========================================================
    // MARK COMPLETE BUTTON
    // ===========================================================

    function snn_education_initMarkComplete() {
        document.querySelectorAll('.snn-mark-complete-btn').forEach(button => {
            button.addEventListener('click', function() {
                snn_education_trackLesson(this.dataset.lessonId, 'completed');
                this.disabled = true;
                this.textContent = this.textContent + ' ✓';
            });
        });
    }

    // ===========================================================
    // EXTERNAL VIDEO EVENTS
    // ===========================================================

    document.addEventListener('snn_video_started', (e) => {
        if (e.detail.lessonId) snn_education_trackLesson(e.detail.lessonId, 'started');
    });

    document.addEventListener('snn_video_completed', (e) => {
        if (e.detail.lessonId) snn_education_trackLesson(e.detail.lessonId, 'completed');
    });

    // ===========================================================
    // INIT ON LOAD
    // ===========================================================

    $(document).ready(function() {
        document.querySelectorAll('.snn-player-wrapper').forEach(wrapper => {
            snn_education_initVideoPlayer(wrapper);
        });
        snn_education_initMarkComplete();
    });

})(jQuery);
