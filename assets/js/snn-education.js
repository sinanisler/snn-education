(function($) {
    'use strict';
    
    // ===========================================================
    // GLOBAL API FUNCTIONS
    // ===========================================================
    
    window.snnEduEnrollUser = function(postId) {
        return fetch(snnEduData.restUrl + 'v1/enroll', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': snnEduData.nonce
            },
            body: JSON.stringify({ post_id: postId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.dispatchEvent(new CustomEvent('snn_edu_enrolled', { detail: { postId } }));
            }
            return data;
        });
    };
    
    window.snnEduUnenrollUser = function(postId) {
        return fetch(snnEduData.restUrl + 'v1/unenroll', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': snnEduData.nonce
            },
            body: JSON.stringify({ post_id: postId })
        })
        .then(response => response.json());
    };
    
    window.snnEduGetEnrollments = function() {
        return fetch(snnEduData.restUrl + 'v1/enrollments', {
            headers: {
                'X-WP-Nonce': snnEduData.nonce
            }
        })
        .then(response => response.json())
        .then(data => data.enrollments || []);
    };
    
    window.snnEduIsEnrolled = function(postId) {
        return snnEduGetEnrollments().then(enrollments => {
            return enrollments.includes(postId);
        });
    };
    
    window.snnEduCompletePost = function(postId) {
        return fetch(snnEduData.restUrl + 'v1/complete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': snnEduData.nonce
            },
            body: JSON.stringify({ course_id: postId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.dispatchEvent(new CustomEvent('snn_edu_completed', { detail: { postId } }));
            }
            return data;
        });
    };
    
    window.snnEduGetCompletions = function() {
        return fetch(snnEduData.restUrl + 'v1/completions', {
            headers: {
                'X-WP-Nonce': snnEduData.nonce
            }
        })
        .then(response => response.json())
        .then(data => data.completions || []);
    };
    
    window.snnEduIsCompleted = function(postId) {
        return snnEduGetCompletions().then(completions => {
            return completions.some(c => c.course_id == postId);
        });
    };
    
    // ===========================================================
    // VIDEO PLAYER
    // ===========================================================
    
    const snn_education_videoPlayers = [];
    
    function snn_education_initVideoPlayer(container) {
        const video = container.querySelector('.snn-video');
        const playBtn = container.querySelector('.snn-play-btn');
        const progressBar = container.querySelector('.snn-progress-bar');
        const timeDisplay = container.querySelector('.snn-time-display');
        const volumeBtn = container.querySelector('.snn-volume-btn');
        const volumeSlider = container.querySelector('.snn-volume-slider');
        const ccBtn = container.querySelector('.snn-cc-btn');
        const ccMenu = container.querySelector('.snn-cc-menu');
        const settingsBtn = container.querySelector('.snn-settings-btn');
        const settingsMenu = container.querySelector('.snn-settings-menu');
        const speedBtn = container.querySelector('.snn-speed-btn');
        const speedMenu = container.querySelector('.snn-speed-menu');
        const fullscreenBtn = container.querySelector('.snn-fullscreen-btn');
        const progressTooltip = container.querySelector('.snn-progress-tooltip');

        // Guard: bail if required elements are missing
        if (!video || !playBtn || !progressBar || !timeDisplay || !volumeBtn || !volumeSlider || !fullscreenBtn) {
            return;
        }
        
        const lessonId = container.dataset.lessonId;
        const events = container.dataset.events;
        const threshold = parseFloat(container.dataset.threshold);
        const requireFull = container.dataset.requireFull === 'true';
        
        let hasStarted = false;
        let hasCompleted = false;
        let watchedTime = 0;
        let lastUpdateTime = 0;
        
        // Play/Pause
        playBtn.addEventListener('click', () => {
            if (video.paused) {
                video.play();
                playBtn.textContent = '⏸';
            } else {
                video.pause();
                playBtn.textContent = '▶';
            }
        });
        
        video.addEventListener('click', () => {
            playBtn.click();
        });
        
        // Progress bar
        video.addEventListener('timeupdate', () => {
            const progress = (video.currentTime / video.duration) * 100;
            progressBar.value = progress;
            
            const currentMin = Math.floor(video.currentTime / 60);
            const currentSec = Math.floor(video.currentTime % 60).toString().padStart(2, '0');
            const durationMin = Math.floor(video.duration / 60);
            const durationSec = Math.floor(video.duration % 60).toString().padStart(2, '0');
            
            timeDisplay.textContent = `${currentMin}:${currentSec} / ${durationMin}:${durationSec}`;
            
            // Track watching time
            const now = Date.now();
            if (lastUpdateTime && now - lastUpdateTime < 2000) {
                watchedTime += (now - lastUpdateTime) / 1000;
            }
            lastUpdateTime = now;
            
            // Fire started event
            if (!hasStarted && watchedTime >= threshold && (events === 'both' || events === 'started')) {
                hasStarted = true;
                document.dispatchEvent(new CustomEvent('snn_video_started', { detail: { lessonId } }));
                
                if (!requireFull) {
                    snn_education_trackLesson(lessonId, 'completed');
                }
            }
        });
        
        progressBar.addEventListener('input', (e) => {
            const time = (e.target.value / 100) * video.duration;
            video.currentTime = time;
        });
        
        // Progress tooltip
        progressBar.addEventListener('mousemove', (e) => {
            const rect = progressBar.getBoundingClientRect();
            const pos = (e.clientX - rect.left) / rect.width;
            const time = pos * video.duration;
            const min = Math.floor(time / 60);
            const sec = Math.floor(time % 60).toString().padStart(2, '0');
            
            progressTooltip.textContent = `${min}:${sec}`;
            progressTooltip.style.left = `${e.clientX - rect.left}px`;
            progressTooltip.style.display = 'block';
        });
        
        progressBar.addEventListener('mouseleave', () => {
            progressTooltip.style.display = 'none';
        });
        
        // Volume
        volumeBtn.addEventListener('click', () => {
            video.muted = !video.muted;
            volumeBtn.textContent = video.muted ? '🔇' : '🔊';
            volumeSlider.value = video.muted ? 0 : video.volume * 100;
        });
        
        volumeSlider.addEventListener('input', (e) => {
            video.volume = e.target.value / 100;
            video.muted = false;
            volumeBtn.textContent = video.volume === 0 ? '🔇' : '🔊';
        });
        
        // Subtitles
        if (ccBtn && ccMenu) {
            ccBtn.addEventListener('click', () => {
                ccMenu.style.display = ccMenu.style.display === 'none' ? 'block' : 'none';
            });
            
            const ccOptions = ccMenu.querySelectorAll('.snn-cc-option');
            ccOptions.forEach(option => {
                option.addEventListener('click', () => {
                    const lang = option.dataset.lang;
                    
                    for (let track of video.textTracks) {
                        track.mode = 'disabled';
                    }
                    
                    if (lang !== 'off') {
                        for (let track of video.textTracks) {
                            if (track.language === lang) {
                                track.mode = 'showing';
                            }
                        }
                    }
                    
                    ccMenu.style.display = 'none';
                });
            });
        }
        
        // Settings
        if (settingsBtn && settingsMenu) {
            settingsBtn.addEventListener('click', () => {
                settingsMenu.style.display = settingsMenu.style.display === 'none' ? 'block' : 'none';
            });
            
            const fontSize = settingsMenu.querySelector('.snn-font-size');
            const textColor = settingsMenu.querySelector('.snn-text-color');
            const bgColor = settingsMenu.querySelector('.snn-bg-color');
            const bgOpacity = settingsMenu.querySelector('.snn-bg-opacity');
            
            function updateCueStyles() {
                const style = document.getElementById('snn-cue-style') || document.createElement('style');
                style.id = 'snn-cue-style';
                style.textContent = `
                    video::cue {
                        font-size: ${fontSize.value}px !important;
                        color: ${textColor.value} !important;
                        background-color: ${bgColor.value}${Math.round(bgOpacity.value * 2.55).toString(16).padStart(2, '0')} !important;
                    }
                `;
                if (!style.parentNode) {
                    document.head.appendChild(style);
                }
            }
            
            fontSize.addEventListener('input', updateCueStyles);
            textColor.addEventListener('input', updateCueStyles);
            bgColor.addEventListener('input', updateCueStyles);
            bgOpacity.addEventListener('input', updateCueStyles);
        }
        
        // Speed
        if (speedBtn && speedMenu) {
            speedBtn.addEventListener('click', () => {
                speedMenu.style.display = speedMenu.style.display === 'none' ? 'block' : 'none';
            });
            
            const speedOptions = speedMenu.querySelectorAll('.snn-speed-option');
            speedOptions.forEach(option => {
                option.addEventListener('click', () => {
                    const speed = parseFloat(option.dataset.speed);
                    video.playbackRate = speed;
                    speedBtn.textContent = `${speed}x`;
                    speedMenu.style.display = 'none';
                });
            });
        }
        
        // Fullscreen
        fullscreenBtn.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                container.requestFullscreen().catch(err => {
                    console.error('Fullscreen error:', err);
                });
                fullscreenBtn.textContent = '⛶';
            } else {
                document.exitFullscreen();
                fullscreenBtn.textContent = '⛶';
            }
        });
        
        // Video ended
        video.addEventListener('ended', () => {
            if (!hasCompleted && (events === 'both' || events === 'completed')) {
                hasCompleted = true;
                document.dispatchEvent(new CustomEvent('snn_video_completed', { detail: { lessonId } }));
                
                if (requireFull) {
                    snn_education_trackLesson(lessonId, 'completed');
                }
            }
        });
        
        // Pause when tab hidden
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                lastUpdateTime = 0;
            } else {
                lastUpdateTime = Date.now();
            }
        });
        
        // Suppress non-critical track/subtitle 404 errors from polluting the console
        for (let i = 0; i < video.textTracks.length; i++) {
            video.textTracks[i].mode = 'disabled';
        }

        snn_education_videoPlayers.push({ container, video, lessonId });
    }
    
    function snn_education_trackLesson(lessonId, status) {
        fetch(snnEduData.restUrl + 'v2/track', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': snnEduData.nonce
            },
            body: JSON.stringify({
                lesson_id: lessonId,
                status: status
            })
        })
        .then(response => response.json())
        .then(data => {
            console.log('Lesson tracked:', status, data);
        });
    }
    
    // ===========================================================
    // MARK COMPLETE BUTTON
    // ===========================================================
    
    function snn_education_initMarkComplete() {
        const buttons = document.querySelectorAll('.snn-mark-complete-btn');
        
        buttons.forEach(button => {
            button.addEventListener('click', function() {
                const lessonId = this.dataset.lessonId;
                
                snn_education_trackLesson(lessonId, 'completed');
                
                this.disabled = true;
                this.textContent = this.textContent + ' ✓';
            });
        });
    }
    
    // ===========================================================
    // LISTEN FOR EXTERNAL VIDEO EVENTS
    // ===========================================================
    
    document.addEventListener('snn_video_started', (e) => {
        console.log('Video started event received:', e.detail);
        if (e.detail.lessonId) {
            snn_education_trackLesson(e.detail.lessonId, 'started');
        }
    });
    
    document.addEventListener('snn_video_completed', (e) => {
        console.log('Video completed event received:', e.detail);
        if (e.detail.lessonId) {
            snn_education_trackLesson(e.detail.lessonId, 'completed');
        }
    });
    
    // ===========================================================
    // INIT ON LOAD
    // ===========================================================
    
    $(document).ready(function() {
        // Initialize all video players
        document.querySelectorAll('.snn-video-container').forEach(container => {
            snn_education_initVideoPlayer(container);
        });
        
        // Initialize mark complete buttons
        snn_education_initMarkComplete();
    });
    
})(jQuery);
