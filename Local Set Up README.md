# Teams Elevated

A full-stack application for managing sports teams and clubs.

## Local Development Setup

### Prerequisites
- Node.js and npm installed
- PHP installed (for backend server)

### Quick Start (Run Both Frontend & Backend)

1. Install dependencies:
```bash
npm install
```

2. Configure environment:
   - Ensure `frontend/.env.local` exists with your API keys
   - Backend should be in `../teamselevated-backend-folder`

3. Start both servers with one command:
```bash
npm start
```

This will start:
- **Frontend** at [http://localhost:5173](http://localhost:5173)
- **Backend** at [http://localhost:3000](http://localhost:3000)

### Individual Server Setup

**Frontend only:**
```bash
npm run start:frontend
```

**Backend only:**
```bash
npm run start:backend
```

## Available Scripts

### `npm start`

Runs the app in the development mode.\
Use `PORT=5173 npm start` to run on port 5173.\
The page will reload if you make edits.\
You will also see any lint errors in the console.

### `npm test`

Launches the test runner in the interactive watch mode.\
See the section about [running tests](https://facebook.github.io/create-react-app/docs/running-tests) for more information.

### `npm run build`

Builds the app for production to the `build` folder.\
It correctly bundles React in production mode and optimizes the build for the best performance.

The build is minified and the filenames include the hashes.\
Your app is ready to be deployed!

See the section about [deployment](https://facebook.github.io/create-react-app/docs/deployment) for more information.

### `npm run eject`

**Note: this is a one-way operation. Once you `eject`, you can’t go back!**

If you aren’t satisfied with the build tool and configuration choices, you can `eject` at any time. This command will remove the single build dependency from your project.

Instead, it will copy all the configuration files and the transitive dependencies (webpack, Babel, ESLint, etc) right into your project so you have full control over them. All of the commands except `eject` will still work, but they will point to the copied scripts so you can tweak them. At this point you’re on your own.

You don’t have to ever use `eject`. The curated feature set is suitable for small and middle deployments, and you shouldn’t feel obligated to use this feature. However we understand that this tool wouldn’t be useful if you couldn’t customize it when you are ready for it.

## Learn More

You can learn more in the [Create React App documentation](https://facebook.github.io/create-react-app/docs/getting-started).

To learn React, check out the [React documentation](https://reactjs.org/).
