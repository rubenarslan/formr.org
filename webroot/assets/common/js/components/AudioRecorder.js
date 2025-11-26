import $ from 'jquery';

export function initializeAudioRecorders() {
    const recordAudioClass = '.record_audio';

    $(recordAudioClass).each(function () {
        const $container = $(this);
        const $fileInput = $container.find('input[type="file"]');
        $fileInput.addClass("hidden");
        const $audioWidget = $('<div class="audio-widget"></div>');
        const $recordBtn = $('<button type="button" class="record-btn btn"><i class="fa fa-microphone"></i></button>');
        const $playBtn = $('<button type="button" class="play-btn btn" disabled><i class="fa fa-play"></i></button>');
        const $deleteBtn = $('<button type="button" class="delete-btn btn" disabled><i class="fa fa-trash"></i></button>');
        const $audioLength = $('<span class="audio-length"></span>');
        let mediaRecorder = null;
        const button_group = $('<div class="btn-group"></div>');
        button_group.append($recordBtn, $playBtn, $deleteBtn);
        let audioChunks = [];
        let audioBlob = null;

        $audioWidget.append(button_group, $audioLength);
		$container.find('label').removeAttr('for');
        $container.find('.controls-inner').append($audioWidget);

        const initMediaRecorder = async () => {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
        
            // Check for supported MIME types
            const mimeType = MediaRecorder.isTypeSupported('audio/webm')
            ? 'audio/webm'
            : MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
            ? 'audio/webm;codecs=opus'
            : MediaRecorder.isTypeSupported('audio/mp4')
            ? 'audio/mp4'
            : MediaRecorder.isTypeSupported('audio/aac')
            ? 'audio/aac'
            : MediaRecorder.isTypeSupported('audio/x-m4a')
            ? 'audio/x-m4a'
            : '';

            if (!mimeType) {
                alert('Your browser does not support recording in the required format.');
                return;
            }
        
            mediaRecorder = new MediaRecorder(stream, { mimeType });
        
            mediaRecorder.ondataavailable = (e) => {
                audioChunks.push(e.data);
            };
        
            mediaRecorder.onstop = async () => {
                audioBlob = new Blob(audioChunks, { type: mimeType }); // Use the correct MIME type
                const audioURL = URL.createObjectURL(audioBlob);
        
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                try {
                    const arrayBuffer = await audioBlob.arrayBuffer(); // Get array buffer from blob
                    const decodedData = await audioContext.decodeAudioData(arrayBuffer); // Decode audio data
                    const duration = decodedData.duration; // Get the duration
        
                    // Format duration to mm:ss
                    const minutes = Math.floor(duration / 60).toString().padStart(2, '0');
                    const seconds = Math.floor(duration % 60).toString().padStart(2, '0');
                    $audioLength.text(` ${minutes}:${seconds}`);
                } catch (error) {
                    console.error('Error decoding audio data:', error);
                    $audioLength.text(' 00:00'); // Fallback if duration can't be determined
                }
        
                // Assign Blob to file input
                const dataTransfer = new DataTransfer();
                const file = new File([audioBlob], "recording.webm", { type: mimeType });
                dataTransfer.items.add(file);
                $fileInput[0].files = dataTransfer.files;
        
                $playBtn.prop('disabled', false);
                $deleteBtn.prop('disabled', false);
            };
        };

        $recordBtn.on('click', async function () {
            if (!mediaRecorder) {
                await initMediaRecorder(); // Ensure MediaRecorder is initialized before recording
            }

            if (mediaRecorder.state === 'inactive') {
                audioChunks = [];
                mediaRecorder.start();
                $recordBtn.find('i').removeClass('fa-microphone').addClass('fa-stop');
            } else {
                mediaRecorder.stop();
                $recordBtn.find('i').removeClass('fa-stop').addClass('fa-microphone');
            }
        });

        $playBtn.on('click', function () {
            if (audioBlob) {
                const audioURL = URL.createObjectURL(audioBlob);
                const audio = new Audio(audioURL);
                audio.play();
            }
        });

        $deleteBtn.on('click', function () {
            audioChunks = [];
            audioBlob = null;
            $fileInput.val('');
            $audioLength.text('');
            $playBtn.prop('disabled', true);
            $deleteBtn.prop('disabled', true);
        });
    });
} 