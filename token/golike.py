import requests

def me(token):
    headers = {
        'accept': 'application/json, text/plain, */*',
        'accept-language': 'vi,en;q=0.9,en-GB;q=0.8,en-US;q=0.7',
        'authorization': token,
        'content-type': 'application/json;charset=utf-8',
        'origin': 'https://app.golike.net',
        'priority': 'u=1, i',
        'sec-ch-ua': '"Chromium";v="152", "Not?A_Brand";v="24", "Google Chrome";v="152"',
        'sec-ch-ua-mobile': '?1',
        'sec-ch-ua-platform': '"iOS"',
        'sec-fetch-dest': 'empty',
        'sec-fetch-mode': 'cors',
        'sec-fetch-site': 'same-site',
        'user-agent': 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1',
    }

    response = requests.get('https://gateway.golike.net/api/users/me', headers=headers)


token = 'Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOlwvXC9nYXRld2F5LmdvbGlrZS5uZXRcL2FwaVwvbG9naW4iLCJpYXQiOjE3ODg4ODY2OTksImV4cCI6MTgyMDQyMjY5OSwibmJmIjoxNzg4ODg2Njk5LCJqdGkiOiJ3aW1OOXNpWENVcXNYd3lIIiwic3ViIjozMTUwMTE5LCJwcnYiOiJiOTEyNzk5NzhmMTFhYTdiYzU2NzA0ODdmZmYwMWUyMjgyNTNmZTQ4In0.7J88gPRXhpxfufehPF9AUrbwbBO9MiEpYbx4SRBDYEE'

response = me(token)