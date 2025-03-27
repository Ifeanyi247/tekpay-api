<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Attempt Notification</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">
    <div class="bg-white shadow-lg rounded-lg p-6 max-w-md w-full">
        <div class="flex items-center space-x-2">
            <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M4.93 4.93a10 10 0 0114.14 0M1 12a11 11 0 1122 0 11 11 0 01-22 0z"></path>
            </svg>
            <h2 class="text-xl font-semibold text-gray-800">Login Attempt</h2>
        </div>

        <p class="mt-4 text-gray-600">We detected a login attempt to your account. If this was you, you can ignore this email. If not, take action immediately.</p>

        <div class="mt-4 p-4 bg-gray-100 rounded-lg">
            <p class="text-gray-700"><strong>Device:</strong> {{ $details['device'] }}</p>
            <p class="text-gray-700"><strong>IP Address:</strong> {{ $details['ip_address'] }}</p>
            <p class="text-gray-700"><strong>Location:</strong> {{ $details['location'] }}</p>
            <p class="text-gray-700"><strong>Time:</strong> {{ now()->format('F j, Y - g:i A') }}</p>
        </div>

        <p class="mt-4 text-gray-500 text-sm">If you recognize this login attempt, no further action is needed.</p>
    </div>
</body>

</html>